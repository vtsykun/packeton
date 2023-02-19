<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Composer\Package\Loader\ArrayLoader;
use Packeton\Mirror\Exception\MetadataNotFoundException;
use Packeton\Mirror\RemoteProxyRepository;
use Symfony\Component\PropertyAccess\PropertyAccess;

class ZipballDownloadManager
{
    protected RemoteProxyRepository $repository;

    public function __construct(
        protected SyncProviderService $service,
        protected Filesystem $filesystem,
        protected string $mirrorDistDir,
    ) {
    }

    public function setRepository(RemoteProxyRepository $repository): void
    {
        $this->repository = $repository;
    }

    public function distPath(string $package, string $version, string $ref): string
    {
        $etag = \preg_replace('#[^a-z0-9-]#i', '-', $version) . '-' . \sha1($package.$version.$ref) . '.zip';
        $distDir = $this->generatePackageDir($package);
        $filename = $distDir . '/' . $etag;
        if ($this->filesystem->exists($filename)) {
            return $filename;
        }

        $packages = $this->repository->findPackageMetadata($package)?->decodeJson();
        $packages = $packages['packages'][$package] ?? [];
        $accessor = PropertyAccess::createPropertyAccessor();

        $search = static function ($packages, $preference) use (&$search, $accessor) {
            $candidate = null;
            [$ref, $propertyPath] = \array_shift($preference);

            foreach ($packages as $package) {
                try {
                    $expectedRef = $accessor->getValue($package, $propertyPath);
                } catch (\Exception) {
                    continue;
                }

                if (!empty($expectedRef) && $expectedRef === $ref) {
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
            [$ref, '[dist][reference]'],
            [$ref, '[source][reference]'],
            [$version, '[version_normalized]'],
        ];

        if (!$candidate = $search($packages, $preference)) {
            throw new MetadataNotFoundException('Not found reference in metadata');
        }

        $http = $this->service->initHttpDownloader($this->repository->getConfig());

        $loader = new ArrayLoader();
        [$package] = $loader->loadPackages([$candidate + ['name' => $package, 'version' => $version]]);
        $urls = $package->getDistUrls();

        $hasFile = false;
        $targetDir = \dirname($filename);
        $this->filesystem->mkdir($targetDir);
        foreach ($urls as $url) {
            try {
                $http->copy($url, $filename);
            } catch (\Exception $e) {
                continue;
            }

            if ($hasFile = $this->filesystem->exists($filename)) {
                break;
            }
        }

        // todo try to download from source.

        if (false === $hasFile) {
            throw new MetadataNotFoundException('Unable to download dist from source');
        }

        return $filename;
    }

    public function generatePackageDir(string $packageName): string
    {
        $intermediatePath = \preg_replace('#[^a-z0-9-_/]#i', '-', $packageName);
        return \sprintf('%s/%s', \rtrim($this->mirrorDistDir, '/'), $intermediatePath);
    }
}
