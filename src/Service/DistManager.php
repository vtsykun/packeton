<?php

declare(strict_types=1);

namespace Packeton\Service;

use Composer\IO\NullIO;
use Packeton\Composer\PackagistFactory;
use Packeton\Entity\Version;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class DistManager
{
    private $fileSystem;

    public function __construct(
        private readonly DistConfig $config,
        private readonly PackagistFactory $packagistFactory
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

    public function lookupInCache(string $reference, string $packageName): ?array
    {
        $finder = new Finder();
        $files = $finder
            ->in($this->config->generateTargetDir($packageName))
            ->name("/$reference/")
            ->files();
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

        return [null, null];
    }

    private function download(Version $version): ?string
    {
        $package = $version->getPackage();
        $io = new NullIO();
        $repository = $this->packagistFactory->createRepository(
            $package->getRepository(),
            $io,
            null,
            $package->getCredentials()
        );

        $archiveManager = $this->packagistFactory->createArchiveManager($io, $repository);
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
}
