<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Composer;

use Composer\Config;
use Composer\IO\IOInterface;
use Packagist\WebBundle\Composer\Repository\VcsRepository;

class VcsRepositoryFactory
{
    /**
     * @var VcsDriverFactory
     */
    protected $driverFactory;

    /**
     * @param VcsDriverFactory $driverFactory
     */
    public function __construct(VcsDriverFactory $driverFactory)
    {
        $this->driverFactory = $driverFactory;
    }

    /**
     * @param array $repoConfig
     * @param IOInterface $io
     * @param Config $config
     *
     * @return VcsRepository
     */
    public function create(array $repoConfig, IOInterface $io, Config $config)
    {
        return new VcsRepository(
            $repoConfig,
            $io,
            $config,
            $this->driverFactory
        );
    }
}
