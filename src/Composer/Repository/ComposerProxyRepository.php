<?php

declare(strict_types=1);

namespace Packeton\Composer\Repository;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Repository\ArrayRepository;
use Composer\Repository\ComposerRepository;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;

class ComposerProxyRepository extends ArrayRepository implements PacketonRepositoryInterface
{
    private string $packageName;
    private ComposerRepository $repository;

    public function __construct(
        protected array $repoConfig,
        protected IOInterface $io,
        protected Config $config,
        protected HttpDownloader $httpDownloader,
        protected ?ProcessExecutor $process = null
    ) {
        parent::__construct();

        $this->repository = new ComposerRepository($repoConfig, $io, $config, $httpDownloader);
        $this->packageName = $this->repoConfig['packageName'];
        $this->process ??= new ProcessExecutor($this->io);
    }

    public function getHttpDownloader(): HttpDownloader
    {
        return $this->httpDownloader;
    }

    public function getPackages(): array
    {
        return $this->repository->findPackages($this->packageName);
    }

    public function getProcessExecutor(): ProcessExecutor
    {
        return $this->process;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getIO(): IOInterface
    {
        return $this->io;
    }

    public function getRepoConfig()
    {
        return $this->repository->getRepoConfig();
    }
}
