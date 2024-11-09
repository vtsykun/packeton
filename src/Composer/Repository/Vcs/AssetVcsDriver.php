<?php

declare(strict_types=1);

namespace Packeton\Composer\Repository\Vcs;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Repository\Vcs\VcsDriver;
use Packeton\Composer\DriverFactoryAwareInterface;
use Packeton\Composer\VcsDriverFactory;

class AssetVcsDriver extends VcsDriver implements DriverFactoryAwareInterface
{
    private VcsDriver $driver;
    private VcsDriverFactory $driverFactory;

    public function initialize(): void
    {
        $repoConfig = $this->repoConfig;
        $repoConfig['driver'] = 'vcs';
        $repoConfig['repoType'] = 'vcs';

        $this->driver = $this->driverFactory->createDriver(
            repoConfig: $repoConfig,
            io: $this->io,
            config: $this->config,
            httpDownloader: $this->httpDownloader,
            process: $this->process,
            classOrType: $repoConfig['driver'],
            options: ['url' => $repoConfig['url']],
        );
    }

    public function getComposerInformation(string $identifier): ?array
    {
        $composer = $this->repoConfig['customComposerJson'] ?? [];
        if ($this->repoConfig['packageName'] ?? null) {
            $composer['name'] = $this->repoConfig['packageName'];
        }

        if (empty($composer['time']) && null !== ($changeDate = $this->getChangeDate($identifier))) {
            $composer['time'] = $changeDate->format(DATE_RFC3339);
        }

        return $composer;
    }

    public function getFileContent(string $file, string $identifier): ?string
    {
        return $this->driver->getFileContent($file, $identifier);
    }

    public function getChangeDate(string $identifier): ?\DateTimeImmutable
    {
        return $this->driver->getChangeDate($identifier);
    }

    public function getRootIdentifier(): string
    {
        return $this->driver->getRootIdentifier();
    }

    public function getBranches(): array
    {
        return $this->driver->getBranches();
    }

    public function getTags(): array
    {
        return $this->driver->getTags();
    }

    public function getDist(string $identifier): ?array
    {
        return $this->driver->getDist($identifier);
    }

    public function getSource(string $identifier): array
    {
        return $this->driver->getSource($identifier);
    }

    public function getUrl(): string
    {
        return $this->driver->getUrl();
    }

    public function hasComposerFile(string $identifier): bool
    {
        return true;
    }

    public function cleanup(): void
    {
        $this->driver->cleanup();
    }

    public static function supports(IOInterface $io, Config $config, string $url, bool $deep = false): bool
    {
        return false;
    }

    public function setDriverFactory(VcsDriverFactory $factory): void
    {
        $this->driverFactory = $factory;
    }
}
