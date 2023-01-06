<?php

declare(strict_types=1);

namespace Packeton\Service;

use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
use Packeton\Composer\PackagistFactory;
use Packeton\Composer\Repository\VcsRepository;
use Packeton\Entity\Package;
use Packeton\Entity\Version;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Finder\Finder;

class DistManager
{
    private $fileSystem;

    public function __construct(
        private readonly DistConfig $config,
        private readonly PackagistFactory $packagistFactory,
    ) {
        $this->fileSystem = new Filesystem();
    }

    public function getDistPath(Version $version): ?string
    {
        $dist = $version->getDist();
        if (false === isset($dist['reference'])) {
            return null;
        }

        $path = $this->config->generateDistFileName($version->getName(), $dist['reference'], $version->getVersion());
        if ($this->fileSystem->exists($path)) {
            try {
                $this->fileSystem->touch($path);
            } catch (IOException) {}

            return $path;
        }

        return $this->download($version);
    }

    public function getDistByOrphanedRef(string $reference, Package $package, &$version = null): string
    {
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
                    $this->fileSystem->touch($file->getRealPath());
                } catch (IOException) {}

                return [$file->getRealPath(), $version];
            }
        }

        return null;
    }

    private function download(Version $version): ?string
    {
        $repository = $this->createRepositoryAndIo($version->getPackage());

        $archiveManager = $this->packagistFactory->createArchiveManager($repository->getIO(), $repository);
        $archiveManager->setOverwriteFiles(false);

        $versions = $repository->getPackages();
        $source = $version->getSource();
        foreach ($versions as $rootVersion) {
            if ($rootVersion->getSourceReference() === $source['reference']) {
                $fileName = $this->config->getFileName($source['reference'], $version->getVersion());
                return $archiveManager->archive(
                    $rootVersion,
                    $this->config->getArchiveFormat(),
                    $this->config->generateTargetDir($version->getName()),
                    $fileName
                );
            }
        }

        return null;
    }

    private function createRepositoryAndIo(Package $package): VcsRepository
    {
        $io = new NullIO();

        return $this->packagistFactory->createRepository(
            $package->getRepository(),
            $io,
            null,
            $package->getCredentials()
        );
    }
}
