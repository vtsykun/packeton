<?php

declare(strict_types=1);

namespace Packeton\Service;

use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Composer\PackagistFactory;
use Packeton\Composer\Repository\PacketonRepositoryInterface;
use Packeton\Entity\Package;
use Packeton\Entity\Version;
use Packeton\Model\UploadZipballStorage;
use Packeton\Package\RepTypes;
use Packeton\Util\PacketonUtils;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Finder\Finder;

class DistManager
{
    public function __construct(
        private readonly DistConfig $config,
        private readonly PackagistFactory $packagistFactory,
        private readonly ManagerRegistry $registry,
        private readonly UploadZipballStorage $storage,
        private readonly Filesystem $fs
    ) {
    }

    public function getDistPath(Version $version): ?string
    {
        if (!$reference = $version->getReference()) {
            return null;
        }

        $package = $version->getPackage();
        $path = $this->config->generateDistFileName($version->getName(), $reference, $version->getVersion());
        if ($this->fs->exists($path)) {
            try {
                $this->fs->touch($path);
            } catch (IOException) {}
            return $path;
        }

        return RepTypes::isBuildInDist($package->getRepoType()) ?
            $this->downloadArtifact($version, $reference, $path)
            : $this->downloadVCS($version, $reference);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnable();
    }

    public function getDistByOrphanedRef(string $reference, Package $package, &$version = null): string
    {
        if (RepTypes::isBuildInDist($package->getRepoType())) {
            throw new \InvalidArgumentException('Unable to found reference for the artifact package');
        }

        if ($cacheRef = $this->lookupInCache($reference, $package->getName())) {
            [$path, $version] = $cacheRef;
            return $path;
        }

        $repository = $this->createRepositoryAndIo($package);
        $versions = $repository->getPackages();

        $selectedVersion = null;
        foreach ($versions as $rootVersion) {
            // Try to create zip archive by hash commit, select the first package and use it as template in composer API
            if ($rootVersion instanceof CompletePackage) {
                $selectedVersion = $rootVersion;
                break;
            }
        }

        $archiveManager = $this->packagistFactory->createArchiveManager($repository->getIO(), $repository);
        $archiveManager->setOverwriteFiles(false);

        if ($selectedVersion instanceof CompletePackage) {
            if ($selectedVersion->getDistReference() && str_contains($selectedVersion->getDistUrl(), $selectedVersion->getDistReference())) {
                $selectedVersion->setDistUrl(str_replace($selectedVersion->getDistReference(), $reference, $selectedVersion->getDistUrl()));
                $selectedVersion->setDistReference($reference);
            } else {
                $selectedVersion->setDistType(null);
                $selectedVersion->setDistReference(null);
                $selectedVersion->setDistUrl(null);
            }

            $selectedVersion->setDistMirrors(null);
            $selectedVersion->setSourceMirrors(null);
            if (str_contains($selectedVersion->getSourceUrl(), $selectedVersion->getSourceReference())) {
                $selectedVersion->setSourceUrl(str_replace($selectedVersion->getSourceReference(), $reference, $selectedVersion->getSourceUrl()));
            }

            $selectedVersion->setSourceReference($reference);

            $version = 'dev-master'; // Used only for check ACL, if exists restriction by versions
            $fileName = $this->config->getFileName($reference, $version);

            return $archiveManager->archive(
                $selectedVersion,
                $this->config->getArchiveFormat(),
                $this->config->generateTargetDir($package->getName()),
                $fileName
            );
        }

        throw new \InvalidArgumentException('Not found reference for the package in cache or VCS');
    }

    private function lookupInCache(string $reference, string $packageName): ?array
    {
        $finder = new Finder();

        try {
            $files = $finder
                ->in($this->config->generateTargetDir($packageName))
                ->name("/$reference/")
                ->files();
        } catch (DirectoryNotFoundException) {
            return null;
        }

        /** @var \SplFileObject $file */
        foreach ($files as $file) {
            $fileName = $file->getFilename();
            if ($version = $this->config->guessesVersion($fileName)) {
                try {
                    $this->fs->touch($file->getRealPath());
                } catch (IOException) {}

                return [$file->getRealPath(), $version];
            }
        }

        return null;
    }

    private function downloadArtifact(Version $version, string $reference, string $cachePath): ?string
    {
        if ($path = $this->storage->getPath($reference)) {
            return $path;
        }

        $repository = $this->createRepositoryAndIo($version->getPackage());
        $packages = $repository->getPackages();
        $found = array_filter($packages, fn($p) => $reference === $p->getDistReference());
        /** @var PackageInterface $package */
        if ($package = reset($found)) {
            $distUrl = $package->getDistUrl();
            if (is_string($distUrl) && str_starts_with($distUrl, '/')) {
                $this->fs->copy($distUrl, $cachePath);
                return $cachePath;
            }
        }

        return null;
    }

    private function downloadVCS(Version $version, string $reference): ?string
    {
        $repository = $this->createRepositoryAndIo($version->getPackage());
        $archiveManager = $this->packagistFactory->createArchiveManager($repository->getIO(), $repository);
        $archiveManager->setOverwriteFiles(false);

        $targetDir = $this->config->generateTargetDir($version->getName());
        $fileName = $this->config->getFileName($reference, $version->getVersion());

        if ($package = $this->tryFromVersion($version)) {
            try {
                return $archiveManager->archive($package, $this->config->getArchiveFormat(), $targetDir, $fileName);
            } catch (\Exception $e) {
                // Try from ref
            }
        }

        if ($package = $this->tryFromReference($repository, $reference)) {
            return $archiveManager->archive($package, $this->config->getArchiveFormat(), $targetDir, $fileName);
        }

        return null;
    }

    private function tryFromVersion(Version $version): ?CompletePackageInterface
    {
        $repo = $this->registry->getRepository(Version::class);
        $data = $repo->getVersionData([$version->getId()]);
        $data = $version->toArray($data);
        unset($data['dist']);
        $loader = new ArrayLoader();

        try {
            return $loader->load($data);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function tryFromReference(RepositoryInterface $repository, string $reference): ?CompletePackageInterface
    {
        $versions = $repository->getPackages();
        foreach ($versions as $version) {
            if ($version->getSourceReference() === $reference && $version instanceof CompletePackageInterface) {
                return $version;
            }
        }

        return null;
    }

    private function createRepositoryAndIo(Package $package): PacketonRepositoryInterface
    {
        $io = new NullIO();

        return $this->packagistFactory->createRepository(
            $package->getRepository(),
            $io,
            null,
            $package->getCredentials(),
            $package->getRepoConfig(),
        );
    }
}
