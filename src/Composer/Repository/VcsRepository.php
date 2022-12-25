<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Composer\Repository;

use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Repository\Vcs\VcsDriver;
use Composer\Repository\Vcs\VcsDriverInterface;
use Composer\Repository\VcsRepository as ComposerVcsRepository;
use Composer\Repository\VersionCacheInterface;
use Packagist\WebBundle\Composer\VcsDriverFactory;

class VcsRepository extends ComposerVcsRepository
{
    protected $drivers;

    /** @var VcsDriverInterface|VcsDriver */
    protected $driver = false;

    /** @var VcsDriverFactory */
    protected $driverFactory;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, VcsDriverFactory $driverFactory, EventDispatcher $dispatcher = null, VersionCacheInterface $versionCache = null)
    {
        parent::__construct($repoConfig, $io, $config, $dispatcher, [], $versionCache);
        $this->driverFactory = $driverFactory;
    }

    /**
     * @return VcsDriver|null
     */
    public function getDriver()
    {
        if (false !== $this->driver) {
            return $this->driver;
        }

        return $this->driver = $this->driverFactory->createDriver(
            $this->repoConfig,
            $this->io,
            $this->config,
            $this->type,
            ['url' => $this->url]
        );
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }
}
