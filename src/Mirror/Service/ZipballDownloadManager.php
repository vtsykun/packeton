<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Composer\Package\CompletePackageInterface;
use Composer\Package\Loader\ArrayLoader;
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

    public function __construct(
        protected SyncProviderService $service,
        protected Filesystem $filesystem,
        protected LoggerInterface $logger,
        protected PackagistFactory $packagistFactory,
        protected string $aliasName,
        protected string $mirrorDistDir,
    ) {
        $this->mirrorDistDir = rtrim($mirrorDistDir, '/') . '/' . $aliasName;
    }

    public function setRepository(RemoteProxyRepository $repository): void
    {
        $this->repository = $repository;
    }

    // ZipArchiver only supported by composer now
    public function distPath(string $package, string $version, string $ref, string $format = 'zip'): string
    {
        $etag = \preg_replace('#[^a-z0-9-]#i', '-', $version) . '-' . \sha1($package.$version.$ref) . '.zip';
        $distDir = $this->generatePackageDir($package);
        $filename = $distDir . '/' . $etag;
        if ($this->filesystem->exists($filename)) {
            return $filename;
        }

        $config = $this->repository->getConfig();
        $packages = $this->repository->findPackageMetadata($package)?->decodeJson();
        $packages = $packages['packages'][$package] ?? [];
        $accessor = PropertyAccess::createPropertyAccessor();

        $search = static function ($packages, $preference) use (&$search, $accessor) {
            $candidate = null;
            [$refs, $propertyPaths] = \array_shift($preference);
            $refs = !is_array($refs) ? [$refs] : $refs;
            $propertyPaths = !is_array($propertyPaths) ? [$propertyPaths] : $propertyPaths;

            foreach ($packages as $package) {
                $match = 0;
                foreach ($propertyPaths as $i => $propertyPath) {
                    $ref = $refs[$i];
                    try {
                        $expectedRef = $accessor->getValue($package, $propertyPath);
                    } catch (\Throwable) {
                        continue;
                    }
                    if (!empty($expectedRef) && $expectedRef === $ref) {
                        $match++;
                    }
                }

                if ($match === count($propertyPaths)) {
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
        [$package] = $loader->loadPackages([$candidate + ['name' => $package, 'version' => $version]]);

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

        $e = null;
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

        return $filename;
    }

    private function createArchiveFormSource(CompletePackageInterface $package, string $url, string $saveTo, ?CredentialsInterface $cred = null): ?string
    {
        $vcsRepository = $this->packagistFactory->createRepository($url, null, null, $cred);
        $vcsRepository->getPackages();

        $archiveManager = $this->packagistFactory->createArchiveManager($vcsRepository->getIO(), $vcsRepository);
        $archiveManager->setOverwriteFiles(false);

        return $archiveManager->archive($package, 'zip', \dirname($saveTo), \basename($saveTo));
    }

    private function generatePackageDir(string $packageName): string
    {
        $intermediatePath = \preg_replace('#[^a-z0-9-_/]#i', '-', $packageName);
        return \sprintf('%s/%s', \rtrim($this->mirrorDistDir, '/'), $intermediatePath);
    }
}
