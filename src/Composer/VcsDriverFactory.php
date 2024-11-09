<?php

declare(strict_types=1);

namespace Packeton\Composer;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Repository\Vcs\VcsDriver;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;

class VcsDriverFactory
{
    /**
     * @var array
     */
    protected $drivers;

    /**
     * @param array $drivers
     */
    public function __construct(array $drivers = [])
    {
        $this->drivers = $drivers ?: [
            'github' => 'Composer\Repository\Vcs\GitHubDriver',
            'gitlab' => 'Composer\Repository\Vcs\GitLabDriver',
            'git-bitbucket' => 'Composer\Repository\Vcs\GitBitbucketDriver',
            'git' => 'Composer\Repository\Vcs\GitDriver',
            'git-tree' => 'Packeton\Composer\Repository\Vcs\TreeGitDriver',
            'hg' => 'Composer\Repository\Vcs\HgDriver',
            'perforce' => 'Composer\Repository\Vcs\PerforceDriver',
            'fossil' => 'Composer\Repository\Vcs\FossilDriver',
            // svn must be last because identifying a subversion server for sure is practically impossible
            'svn' => 'Composer\Repository\Vcs\SvnDriver',
            'asset' => 'Packeton\Composer\Repository\Vcs\AssetVcsDriver',
        ];
    }

    /**
     * @param string $type
     * @param string $class
     */
    public function setDriverClass(string $type, string $class): void
    {
        $this->drivers[$type] = $class;
    }


    /**
     * @param array $repoConfig
     * @param IOInterface $io
     * @param Config $config
     * @param HttpDownloader $httpDownloader
     * @param ProcessExecutor $process
     * @param string|null $classOrType
     * @param array $options
     *
     * @return VcsDriver
     */
    public function createDriver(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader, ProcessExecutor $process, ?string $classOrType = null, array $options = []): VcsDriver
    {
        /** @var VcsDriver|null $driver */
        $driver = null;
        if ($classOrType && class_exists($classOrType)) {
            $driver = new $classOrType($repoConfig, $io, $config, $process);
        }

        if (null === $driver && null !== $classOrType && isset($this->drivers[$classOrType])) {
            $class = $this->drivers[$classOrType];
            $driver = new $class($repoConfig, $io, $config, $httpDownloader, $process);
        }

        if (null === $driver && isset($options['url'])) {
            foreach ($this->drivers as $driverClass) {
                if ($driverClass::supports($io, $config, $options['url'])) {
                    $driver = new $driverClass($repoConfig, $io, $config, $httpDownloader, $process);
                    break;
                }
            }
        }

        if (null === $driver && isset($options['url'])) {
            foreach ($this->drivers as $driverClass) {
                if ($driverClass::supports($io, $config, $options['url'], true)) {
                    $driver = new $driverClass($repoConfig, $io, $config, $httpDownloader, $process);
                    break;
                }
            }
        }

        if (null === $driver) {
            $repoUrl = $options['url'] ?? null;
            throw new \UnexpectedValueException("VCS Driver not found for repository $repoUrl");
        }

        if ($driver instanceof DriverFactoryAwareInterface) {
            $driver->setDriverFactory($this);
        }

        if (!($options['lazy'] ?? false)) {
            $driver->initialize();
        }

        return $driver;
    }
}
