<?php

declare(strict_types=1);

namespace Packeton\Composer\Repository;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Repository\ComposerRepository;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;

class ComposerProxyRepository extends ComposerRepository implements PacketonRepositoryInterface
{
    public function __construct(
        protected array $repoConfig,
        protected IOInterface $io,
        protected Config $config,
        protected HttpDownloader $httpDownloader,
        protected ?ProcessExecutor $process = null
    ) {
        parent::__construct($repoConfig, $io, $config, $httpDownloader);

        $this->process ??= new ProcessExecutor($this->io);
    }

    public function getHttpDownloader(): HttpDownloader
    {
        return $this->httpDownloader;
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
}
