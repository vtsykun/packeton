<?php

declare(strict_types=1);

namespace Packeton\Composer\Repository;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\LoaderInterface;
use Composer\Repository\ArrayRepository;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;
use Doctrine\Persistence\ManagerRegistry;

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

    public function getRepoConfig()
    {
        return $this->repoConfig;
    }

    public function getProcessExecutor(): ProcessExecutor
    {
        return $this->process;
    }

    public function getHttpDownloader(): HttpDownloader
    {
        return $this->httpDownloader;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getIO(): IOInterface
    {
        return $this->io;
    }
}
