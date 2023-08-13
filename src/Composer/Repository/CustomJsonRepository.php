<?php

declare(strict_types=1);

namespace Packeton\Composer\Repository;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\LoaderInterface;
use Composer\Repository\ArrayRepository;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Zipball;
use Packeton\Service\DistConfig;

class CustomJsonRepository extends ArrayRepository implements PacketonRepositoryInterface
{
    protected LoaderInterface $loader;

    public function __construct(
        protected array $repoConfig,
        protected ManagerRegistry $registry,
        protected IOInterface $io,
        protected Config $config,
        protected HttpDownloader $httpDownloader,
        protected ?ProcessExecutor $process = null
    ) {
        parent::__construct();

        $this->loader = new ArrayLoader();
        $this->process ??= new ProcessExecutor($this->io);
    }

    public function setLoader(LoaderInterface $loader): void
    {
        $this->loader = $loader;
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
     * {@inheritdoc}
     */
    public function getRepoName(): string
    {
        return "Custom JSON repo";
    }

    /**
     * {@inheritdoc}
     */
    protected function initialize(): void
    {
        parent::initialize();

        $this->doInitialize();
    }

    protected function doInitialize(): void
    {
        foreach ($this->repoConfig['customVersions'] as $version) {
            $this->addPackage($this->loadVersion($version));
        }
    }

    protected function loadVersion(array $version): BasePackage
    {
        $data = $version['definition'] ?? [];

        $data['name'] = $this->repoConfig['packageName'];
        $data['version'] ??= $version['version'];

        if ($version['dist'] ?? null) {
            $dist = $this->registry->getRepository(Zipball::class)->find($version['dist']);

            $fileExtension = $dist->getExtension();
            if (in_array($fileExtension, ['gz', 'tar', 'tgz'], true)) {
                $fileType = 'tar';
            } elseif ($fileExtension === 'zip') {
                $fileType = 'zip';
            } else {
                throw new \RuntimeException('Files with "'.$fileExtension.'" extensions aren\'t supported. Only ZIP and TAR/TAR.GZ/TGZ archives are supported.');
            }

            $data['dist'] = [
                'type' => $fileType,
                'reference' => $dist->getReference(),
                'url' => DistConfig::HOSTNAME_PLACEHOLDER,
            ];
        }

        return $this->loader->load($data);
    }
}
