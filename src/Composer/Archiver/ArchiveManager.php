<?php

declare(strict_types=1);

namespace Packeton\Composer\Archiver;

use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Archiver\ArchiveManager as ComposerArchiveManager;
use Composer\Package\Archiver\PharArchiver;
use Composer\Package\Archiver\ZipArchiver;
use Composer\Package\CompletePackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Util\ProcessExecutor;
use Composer\Util\SyncHelper;
use Packeton\Composer\Repository\Vcs\TreeGitDriver;
use Packeton\Util\PacketonUtils;

class ArchiveManager extends ComposerArchiveManager
{
    protected $subDirectory;

    public function __construct(
        DownloadManager $downloadManager,
        Loop $loop,
        protected Config $config,
        protected ProcessExecutor $processExecutor,
        protected array $repoConfig,
        protected IOInterface $io,
        protected HttpDownloader $httpDownloader
    ) {
        parent::__construct($downloadManager, $loop);
    }

    public function setSubDirectory(?string $subDir): void
    {
        $this->subDirectory = $subDir;
    }

    /**
     * {@inheritdoc}
     */
    public function archive(CompletePackageInterface $package, string $format, string $targetDir, ?string $fileName = null, bool $ignoreFilters = false): string
    {
        if (empty($format)) {
            throw new \InvalidArgumentException('Format must be specified');
        }

        try {
            if ($path = $this->tryFromGitArchive($package, $format, $targetDir, $fileName)) {
                return $path;
            }
        } catch (\Exception $e) {
        }

        // Search for the most appropriate archiver
        $usableArchiver = null;
        foreach ($this->archivers as $archiver) {
            if ($archiver->supports($format, $package->getSourceType())) {
                $usableArchiver = $archiver;
                break;
            }
        }

        // Checks the format/source type are supported before downloading the package
        if (null === $usableArchiver) {
            throw new \RuntimeException(sprintf('No archiver found to support %s format', $format));
        }

        $filesystem = new Filesystem();

        if ($package instanceof RootPackageInterface) {
            $resolvedSourcePath = $sourcePath = realpath('.');
        } else {
            // Directory used to download the sources
            $sourcePath = sys_get_temp_dir().'/composer_archive'.uniqid();
            $filesystem->ensureDirectoryExists($sourcePath);

            try {
                // Download sources
                $promise = $this->downloadManager->download($package, $sourcePath);
                SyncHelper::await($this->loop, $promise);
                $promise = $this->downloadManager->install($package, $sourcePath);
                SyncHelper::await($this->loop, $promise);
            } catch (\Exception $e) {
                $filesystem->removeDirectory($sourcePath);
                throw  $e;
            }

            $resolvedSourcePath = $this->subDirectory ?  $sourcePath . '/' . trim($this->subDirectory, '/') : $sourcePath;

            // Check exclude from downloaded composer.json
            if (file_exists($composerJsonPath = $resolvedSourcePath.'/composer.json')) {
                $jsonFile = new JsonFile($composerJsonPath);
                $jsonData = $jsonFile->read();
                if (!empty($jsonData['archive']['name'])) {
                    $package->setArchiveName($jsonData['archive']['name']);
                }
                if (!empty($jsonData['archive']['exclude'])) {
                    $package->setArchiveExcludes($jsonData['archive']['exclude']);
                }
            }
        }

        $supportedFormats = $this->getSupportedFormats();
        $packageNameParts = null === $fileName ?
            $this->getPackageFilenameParts($package)
            : ['base' => $fileName];

        $packageName = $this->getPackageFilenameFromParts($packageNameParts);
        $excludePatterns = $this->buildExcludePatterns($packageNameParts, $supportedFormats);

        // Archive filename
        $filesystem->ensureDirectoryExists($targetDir);
        $target = realpath($targetDir).'/'.$packageName.'.'.$format;
        $filesystem->ensureDirectoryExists(dirname($target));

        if (!$this->overwriteFiles && file_exists($target)) {
            return $target;
        }

        // Create the archive
        $tempTarget = sys_get_temp_dir().'/composer_archive'.uniqid().'.'.$format;
        $filesystem->ensureDirectoryExists(dirname($tempTarget));

        $archivePath = $usableArchiver->archive(
            $resolvedSourcePath,
            $tempTarget,
            $format,
            array_merge($excludePatterns, $package->getArchiveExcludes()),
            $ignoreFilters
        );
        $filesystem->rename($archivePath, $target);

        // cleanup temporary download
        if (!$package instanceof RootPackageInterface) {
            $filesystem->removeDirectory($sourcePath);
        }
        $filesystem->remove($tempTarget);

        return $target;
    }

    public function tryFromGitArchive(CompletePackageInterface $package, string $format, string $targetDir, ?string $fileName = null): ?string
    {
        if ($package->getSourceType() !== 'git') {
            return null;
        }

        $filesystem = new Filesystem();
        $driver = new TreeGitDriver($this->repoConfig, $this->io, $this->config, $this->httpDownloader, $this->processExecutor);
        $driver = $driver->withSubDirectory($this->subDirectory);

        PacketonUtils::toggleNetwork(false);
        try {
            $driver->initialize();
        } finally {
            PacketonUtils::toggleNetwork(true);
        }

        try {
            $jsonData = $driver->getComposerInformation($package->getSourceReference());
            if (empty($jsonData)) {
                return null;
            }
        } catch (\Exception $e) {
            return null;
        }

        $packageNameParts = null === $fileName ?
            $this->getPackageFilenameParts($package)
            : ['base' => $fileName];

        $packageName = $this->getPackageFilenameFromParts($packageNameParts);

        $filesystem->ensureDirectoryExists($targetDir);
        $target = realpath($targetDir).'/'.$packageName.'.'.$format;
        $filesystem->ensureDirectoryExists(dirname($target));

        if (!$this->overwriteFiles && file_exists($target)) {
            return $target;
        }
        // Create the archive
        $tempTarget = sys_get_temp_dir().'/composer_archive'.uniqid().'.'.$format;
        $filesystem->ensureDirectoryExists(dirname($tempTarget));

        try {
            $tempTarget = $driver->makeArchive($package->getSourceReference(), $tempTarget, $format);
        } catch (\Exception $e) {
            return null;
        }

        if ($tempTarget) {
            $filesystem->rename($tempTarget, $target);
            $filesystem->remove($tempTarget);
            return $target;
        }

        return null;
    }

    /**
     * @param string[] $parts
     * @param string[] $formats
     *
     * @return string[]
     */
    private function buildExcludePatterns(array $parts, array $formats): array
    {
        $base = $parts['base'];
        if (count($parts) > 1) {
            $base .= '-*';
        }

        $patterns = [];
        foreach ($formats as $format) {
            $patterns[] = "$base.$format";
        }

        return $patterns;
    }

    /**
     * @return string[]
     */
    private function getSupportedFormats(): array
    {
        // The problem is that the \Composer\Package\Archiver\ArchiverInterface
        // doesn't provide method to get the supported formats.
        // Supported formats are also hard-coded into the description of the
        // --format option.
        // See \Composer\Command\ArchiveCommand::configure().
        $formats = [];
        foreach ($this->archivers as $archiver) {
            $items = [];
            switch (get_class($archiver)) {
                case ZipArchiver::class:
                    $items = ['zip'];
                    break;

                case PharArchiver::class:
                    $items = ['zip', 'tar', 'tar.gz', 'tar.bz2'];
                    break;
            }

            $formats = array_merge($formats, $items);
        }

        return array_unique($formats);
    }
}
