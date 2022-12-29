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

class VcsRepository extends ComposerVcsRepository
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
            $this->type,
            ['url' => $this->url]
        );
    }

    /**
     * @return HttpDownloader
     */
    public function getHttpDownloader()
    {
        return $this->httpDownloader;
    }

    /**
     * @return ProcessExecutor
     */
    public function getProcessExecutor()
    {
        return $this->processExecutor;
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }
}
