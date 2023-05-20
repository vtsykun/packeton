<?php

declare(strict_types=1);

namespace Packeton\Composer\Repository;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\LoaderInterface;
use Composer\Repository\ArrayRepository;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;
use Composer\Util\Tar;
use Composer\Util\Zip;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Zipball;
use Packeton\Model\UploadZipballStorage;

/**
 * Artifact repository where attachment's information stored in database
 */
class ArtifactRepository extends ArrayRepository implements PacketonRepositoryInterface
{
    /** @var LoaderInterface */
    protected $loader;

    /** @var string */
    protected $lookup;

    /** @var array */
    protected $archives;

    public function __construct(
        protected array $repoConfig,
        protected UploadZipballStorage $storage,
        protected ManagerRegistry $registry,
        protected IOInterface $io,
        protected Config $config,
        protected HttpDownloader $httpDownloader,
        protected ?ProcessExecutor $process = null
    ) {
        parent::__construct();
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('The artifact repository requires PHP\'s zip extension');
        }

        $this->loader = new ArrayLoader();
        $this->lookup = $repoConfig['url'] ?? null;
        $this->lookup = $this->lookup === '_unset' ? null : $this->lookup;
        $this->archives = $this->repoConfig['archives'] ?? [];

        $this->process ??= new ProcessExecutor($this->io);
    }

    public function setLoader(LoaderInterface $loader): void
    {
        $this->loader = $loader;
    }

    /**
     * {@inheritdoc}
     */
    public function getRepoName(): string
    {
        $name = $this->lookup ? "({$this->lookup})" : json_encode($this->archives);
        return "artifact repo $name";
    }

    /**
     * {@inheritdoc}
     */
    protected function initialize(): void
    {
        parent::initialize();

        $this->doInitialize();
    }

    /**
     * {@inheritdoc}
     */
    public function getRepoConfig(): array
    {
        return $this->repoConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function getProcessExecutor(): ProcessExecutor
    {
        return $this->process;
    }

    /**
     * {@inheritdoc}
     */
    public function getHttpDownloader(): HttpDownloader
    {
        return $this->httpDownloader;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * {@inheritdoc}
     */
    public function getIO(): IOInterface
    {
        return $this->io;
    }

    /**
     * @return iterable|\SplFileInfo[]
     */
    public function allArtifacts(): iterable
    {
        if ($this->lookup) {
            yield from $this->scanDirectory($this->lookup);
        }

        if ($this->archives) {
            yield from $this->scanArchives($this->archives);
        }
    }

    private function doInitialize(): void
    {
        foreach ($this->allArtifacts() as $file) {
            $package = $this->getComposerInformation($file);
            if (!$package) {
                $this->io->writeError("File <comment>{$file->getBasename()}</comment> doesn't seem to hold a package", true, IOInterface::VERBOSE);
                continue;
            }

            $template = 'Found package <info>%s</info> (<comment>%s</comment>) in file <info>%s</info>';
            $this->io->writeError(sprintf($template, $package->getName(), $package->getPrettyVersion(), $file->getBasename()), true, IOInterface::VERBOSE);

            $this->addPackage($package);
        }
    }

    private function scanArchives(array $archives): iterable
    {
        foreach ($archives as $archive) {
            $zip = $this->registry->getRepository(Zipball::class)->find($archive);
            if (null === $zip) {
                $this->io->writeError("Archive #$archive was removed from database");
                continue;
            }

            $path = $this->storage->getPath($zip);
            if (!file_exists($path)) {
                $this->io->writeError("Archive #$archive was removed from storage '$path'");
                continue;
            }

            yield new \SplFileInfo($path);
        }
    }

    /**
     * @param string $path
     * @return iterable|\SplFileInfo[]
     */
    private function scanDirectory(string $path): iterable
    {
        $directory = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::FOLLOW_SYMLINKS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $regex = new \RegexIterator($iterator, '/^.+\.(zip|tar|gz|tgz)$/i');
        foreach ($regex as $file) {
            /* @var $file \SplFileInfo */
            if (!$file->isFile()) {
                continue;
            }

            yield $file;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getComposerInformation(\SplFileInfo $file): ?BasePackage
    {
        $json = null;
        $fileExtension = pathinfo($file->getPathname(), PATHINFO_EXTENSION);
        if (in_array($fileExtension, ['gz', 'tar', 'tgz'], true)) {
            $fileType = 'tar';
        } elseif ($fileExtension === 'zip') {
            $fileType = 'zip';
        } else {
            throw new \RuntimeException('Files with "'.$fileExtension.'" extensions aren\'t supported. Only ZIP and TAR/TAR.GZ/TGZ archives are supported.');
        }

        try {
            if ($fileType === 'tar') {
                $json = Tar::getComposerJson($file->getPathname());
            } else {
                $json = Zip::getComposerJson($file->getPathname());
            }
        } catch (\Exception $exception) {
            $this->io->write('Failed loading package '.$file->getPathname().': '.$exception->getMessage(), false, IOInterface::VERBOSE);
        }

        if (null === $json) {
            return null;
        }

        $package = JsonFile::parseJson($json, $file->getPathname().'#composer.json');
        $package['dist'] = [
            'type' => $fileType,
            'url' => strtr($file->getPathname(), '\\', '/'),
            'shasum' => sha1_file($file->getRealPath()),
        ];

        try {
            $package = $this->loader->load($package);
        } catch (\UnexpectedValueException $e) {
            throw new \UnexpectedValueException('Failed loading package in '.$file.': '.$e->getMessage(), 0, $e);
        }

        return $package;
    }
}
