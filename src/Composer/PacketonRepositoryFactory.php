<?php

declare(strict_types=1);

namespace Packeton\Composer;

use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Composer\Repository\ArtifactRepository;
use Packeton\Composer\Repository\PacketonRepositoryInterface;
use Packeton\Composer\Repository\VcsRepository;
use Packeton\Composer\Util\ProcessExecutor;
use Packeton\Model\UploadZipballStorage;

class PacketonRepositoryFactory
{
    public function __construct(
        protected VcsDriverFactory $driverFactory,
        protected ManagerRegistry $registry,
        protected UploadZipballStorage $zipballStorage
    ) {
    }

    /**
     * @param array $repoConfig
     * @param IOInterface $io
     * @param Config $config
     * @param string|null $type
     *
     * @return PacketonRepositoryInterface
     */
    public function create(array $repoConfig, IOInterface $io, Config $config, ?string $type = null): PacketonRepositoryInterface
    {
        $httpDownloader = Factory::createHttpDownloader($io, $config);
        $process = new ProcessExecutor($io);
        $type ??= 'vcs';

        return match ($type) {
            'artifact' => new ArtifactRepository($repoConfig, $this->zipballStorage, $this->registry, $io, $config, $httpDownloader),
            default => new VcsRepository($repoConfig, $io, $config, $httpDownloader, $this->driverFactory, null, $process),
        };
    }
}
