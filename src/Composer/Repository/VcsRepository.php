<?php

declare(strict_types=1);

namespace Packeton\Composer\Repository;

use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Repository\Vcs\VcsDriver;
use Composer\Repository\Vcs\VcsDriverInterface;
use Composer\Repository\VcsRepository as ComposerVcsRepository;
use Composer\Repository\VersionCacheInterface;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;
use Packeton\Composer\VcsDriverFactory;

class VcsRepository extends ComposerVcsRepository implements PacketonRepositoryInterface
{
    protected $drivers;

    /** @var VcsDriverInterface|VcsDriver */
    protected $driver = false;

    /** @var VcsDriverFactory */
    protected $driverFactory;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader, VcsDriverFactory $driverFactory, EventDispatcher $dispatcher = null, ?ProcessExecutor $process = null, ?VersionCacheInterface $versionCache = null)
    {
        parent::__construct($repoConfig, $io, $config, $httpDownloader, $dispatcher, $process, [], $versionCache);
        $this->driverFactory = $driverFactory;

        // Disable https://github.com/composer/composer/pull/11453
        $this->url = $repoConfig['url'];
        $this->repoConfig = $repoConfig;
    }

    public function setDriver(VcsDriverInterface $driver): void
    {
        $this->driver = $driver;
    }

    /**
     * @return VcsDriver|null
     */
    public function getDriver(): ?VcsDriverInterface
    {
        if (false !== $this->driver) {
            return $this->driver;
        }

        return $this->driver = $this->driverFactory->createDriver(
            $this->repoConfig,
            $this->io,
            $this->config,
            $this->httpDownloader,
            $this->processExecutor,
            $this->repoConfig['driver'] ?? $this->type,
            ['url' => $this->url]
        );
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
    public function getProcessExecutor(): ProcessExecutor
    {
        return $this->processExecutor;
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
}
