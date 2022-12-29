<?php

declare(strict_types=1);

namespace Packeton\Composer;

use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Packeton\Composer\Repository\VcsRepository;
use Packeton\Composer\Util\ProcessExecutor;

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
        $httpDownloader = Factory::createHttpDownloader($io, $config);
        $process = new ProcessExecutor($io);

        return new VcsRepository(
            $repoConfig,
            $io,
            $config,
            $httpDownloader,
            $this->driverFactory,
            null,
            $process
        );
    }
}
