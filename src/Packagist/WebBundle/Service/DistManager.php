<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Service;

use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Repository\VcsRepository;
use Packagist\WebBundle\Entity\Version;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class DistManager
{
    private $config;
    private $fileSystem;

    public function __construct(DistConfig $config)
    {
        $this->config = $config;
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
                return [$file->getRealPath(), $version];
            }
        }

        return [null, null];
    }

    private function download(Version $version): ?string
    {
        $package = $version->getPackage();
        $package->loadCredentials();

        $io = new BufferIO('', StreamOutput::VERBOSITY_VERBOSE);
        $config = Factory::createConfig();
        $io->loadConfiguration($config);
        $repository = new VcsRepository(['url' => $package->getRepository()], $io, $config);

        $factory = new Factory();
        $dm = $factory->createDownloadManager($io, $config);
        $archiveManager = $factory->createArchiveManager($config, $dm);
        $archiveManager->setOverwriteFiles(false);

        $versions = $repository->getPackages();
        $source = $version->getSource();
        foreach ($versions as $rootVersion) {
            if ($rootVersion->getSourceReference() === $source['reference']) {
                $fileName = $this->config->getFileName($source['reference'], $version->getVersion());
                $path = $archiveManager->archive(
                    $rootVersion,
                    $this->config->getArchiveFormat(),
                    $this->config->generateTargetDir($version->getName()),
                    $fileName
                );

                return $path;
            }
        }

        return null;
    }
}
