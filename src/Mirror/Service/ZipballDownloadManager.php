<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Composer\Package\CompletePackageInterface;
use Composer\Package\Loader\ArrayLoader;
use League\Flysystem\FilesystemOperator;
use Packeton\Composer\PackagistFactory;
use Packeton\Mirror\Exception\MetadataNotFoundException;
use Packeton\Mirror\RemoteProxyRepository;
use Packeton\Model\CredentialsInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;

class ZipballDownloadManager
{
    protected RemoteProxyRepository $repository;
    protected string $localCacheDir;

    public function __construct(
        protected SyncProviderService $service,
        protected Filesystem $filesystem,
        protected LoggerInterface $logger,
        protected PackagistFactory $packagistFactory,
        protected string $aliasName,
        protected string $mirrorDistDir,
        protected FilesystemOperator $mirrorDistStorage,
        protected ?string $mirrorDistCacheDir = null,
    ) {
        $this->localCacheDir = rtrim($mirrorDistDir, '/') . '/' . $aliasName;
    }

    public function setRepository(RemoteProxyRepository $repository): void
    {
        $this->repository = $repository;
    }

    /**
     * Returns stream for direct S3 access when local file unavailable.
     *
     * @return resource|null
     */
    public function distStream(string $package, string $version, string $ref, string $format = 'zip'): mixed
    {
        $etag = $this->buildEtag($package, $version, $ref);
        $storageKey = $this->buildStorageKey($package, $etag);

        if ($this->mirrorDistStorage->fileExists($storageKey)) {
            return $this->mirrorDistStorage->readStream($storageKey);
        }

        return null;
    }

    /**
     * Returns local file path, downloading from upstream or S3 cache if needed.
     */
    public function distPath(string $package, string $version, string $ref, string $format = 'zip'): string
    {
        $etag = $this->buildEtag($package, $version, $ref);
        $localPath = $this->buildLocalPath($package, $etag);
        $storageKey = $this->buildStorageKey($package, $etag);

        // Check local cache first
        if ($this->filesystem->exists($localPath)) {
            return $localPath;
        }

        // Check remote storage (S3)
        if ($this->mirrorDistStorage->fileExists($storageKey)) {
            $this->copyToLocal($storageKey, $localPath);
            return $localPath;
        }

        // Download from upstream
        $this->downloadFromUpstream($package, $version, $ref, $localPath);

        // Upload to remote storage
        $this->uploadToStorage($localPath, $storageKey);

        return $localPath;
    }

    protected function buildEtag(string $package, string $version, string $ref): string
    {
        return \preg_replace('#[^a-z0-9-]#i', '-', $version) . '-' . \sha1($package . $version . $ref) . '.zip';
    }

    protected function buildStorageKey(string $package, string $etag): string
    {
        // Sharding based on package name hash for S3 performance
        $hash = \sha1($package);
        $shard = \substr($hash, 0, 2) . '/' . \substr($hash, 2, 2);

        $intermediatePath = \preg_replace('#[^a-z0-9-_/]#i', '-', $package);

        return \sprintf('%s/%s/%s/%s', $shard, $this->aliasName, $intermediatePath, $etag);
    }

    protected function buildLocalPath(string $package, string $etag): string
    {
        $intermediatePath = \preg_replace('#[^a-z0-9-_/]#i', '-', $package);

        // Use cache dir if configured, otherwise use default local dir
        $baseDir = ($this->mirrorDistCacheDir !== null && $this->mirrorDistCacheDir !== '')
            ? \rtrim($this->mirrorDistCacheDir, '/') . '/' . $this->aliasName
            : $this->localCacheDir;

        return \sprintf('%s/%s/%s', $baseDir, $intermediatePath, $etag);
    }

    protected function copyToLocal(string $storageKey, string $localPath): void
    {
        $stream = $this->mirrorDistStorage->readStream($storageKey);
        $targetDir = \dirname($localPath);

        if (!$this->filesystem->exists($targetDir)) {
            $this->filesystem->mkdir($targetDir);
        }

        $localHandle = @\fopen($localPath, 'w+b');
        if ($localHandle) {
            @\stream_copy_to_stream($stream, $localHandle);
            @\fclose($localHandle);
        }

        if (\is_resource($stream)) {
            @\fclose($stream);
        }
    }

    protected function uploadToStorage(string $localPath, string $storageKey): void
    {
        if (!$this->mirrorDistStorage->fileExists($storageKey)) {
            $stream = @\fopen($localPath, 'r');
            if ($stream) {
                $this->mirrorDistStorage->writeStream($storageKey, $stream);
                @\fclose($stream);
            }
        }
    }

    protected function downloadFromUpstream(string $packageName, string $version, string $ref, string $filename): void
    {
        $config = $this->repository->getConfig();
        $packages = $this->repository->findPackageMetadata($packageName)?->decodeJson();
        $packages = $packages['packages'][$packageName] ?? [];
        $accessor = PropertyAccess::createPropertyAccessor();

        $search = static function ($packages, $preference) use (&$search, $accessor) {
            $candidate = null;
            [$refs, $propertyPaths] = \array_shift($preference);
            $refs = !\is_array($refs) ? [$refs] : $refs;
            $propertyPaths = !\is_array($propertyPaths) ? [$propertyPaths] : $propertyPaths;

            foreach ($packages as $package) {
                $match = 0;
                foreach ($propertyPaths as $i => $propertyPath) {
                    $refValue = $refs[$i];
                    try {
                        $expectedRef = $accessor->getValue($package, $propertyPath);
                    } catch (\Throwable) {
                        continue;
                    }
                    if (!empty($expectedRef) && $expectedRef === $refValue) {
                        $match++;
                    }
                }

                if ($match === \count($propertyPaths)) {
                    $candidate = $package;
                    break;
                }
            }

            if (null === $candidate && $preference) {
                return $search($packages, $preference);
            }
            return $candidate;
        };

        $preference = [
            [[$ref, $version], ['[dist][reference]', '[version_normalized]']],
            [[$ref, $version], ['[source][reference]', '[version_normalized]']],
            [$ref, '[dist][reference]'],
            [$ref, '[source][reference]'],
            [$version, '[version_normalized]'],
        ];

        if (!$candidate = $search($packages, $preference)) {
            throw new MetadataNotFoundException('Not found reference in metadata');
        }

        $http = $this->service->initHttpDownloader($config);

        $loader = new ArrayLoader();
        [$package] = $loader->loadPackages([$candidate + ['name' => $packageName, 'version' => $version]]);

        if ($mirrors = $config->getParentMirrors()) {
            $distMirrors = [];
            foreach ($mirrors as $mirror) {
                if (isset($mirror['dist-url'])) {
                    $distMirrors[] = ['url' => $config->getUrl($mirror['dist-url']), 'preferred' => $mirror['preferred'] ?? false];
                }
            }
            $package->setDistMirrors($distMirrors);
        }

        $cause = '';
        $hasFile = false;
        $targetDir = \dirname($filename);
        $this->filesystem->mkdir($targetDir);
        $urls = $package->getDistUrls();

        foreach ($urls as $url) {
            try {
                $http->copy($url, $filename);
            } catch (\Exception $e) {
                $cause .= $e->getMessage() . '. ';
                continue;
            }

            if ($hasFile = $this->filesystem->exists($filename)) {
                break;
            }
        }

        if (false === $hasFile) {
            foreach ($package->getSourceUrls() as $url) {
                $cred = $config->getSshCredential($url);
                try {
                    $generated = $this->createArchiveFormSource($package, $url, $filename, $cred);
                } catch (\Throwable $ex) {
                    $this->logger->error($ex->getMessage(), ['e' => $ex]);
                    $cause .= $ex->getMessage();
                    continue;
                }

                if (null !== $generated) {
                    $hasFile = true;
                    if ($generated !== $filename) {
                        $this->filesystem->rename($generated, $filename);
                    }

                    break;
                }
            }
        }

        if (false === $hasFile) {
            throw new MetadataNotFoundException('Unable to download dist from source. ' . $cause);
        }
    }

    private function createArchiveFormSource(CompletePackageInterface $package, string $url, string $saveTo, ?CredentialsInterface $cred = null): ?string
    {
        $vcsRepository = $this->packagistFactory->createRepository($url, null, null, $cred);
        $vcsRepository->getPackages();

        $archiveManager = $this->packagistFactory->createArchiveManager($vcsRepository->getIO(), $vcsRepository);
        $archiveManager->setOverwriteFiles(false);

        return $archiveManager->archive($package, 'zip', \dirname($saveTo), \basename($saveTo));
    }
}
