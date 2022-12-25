<?php

declare(strict_types=1);

namespace Packeton\Composer;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Repository\Vcs\VcsDriver;
use Composer\Repository\Vcs\VcsDriverInterface;
use Packeton\Composer\Util\ProcessExecutor;

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
            'hg' => 'Composer\Repository\Vcs\HgDriver',
            'perforce' => 'Composer\Repository\Vcs\PerforceDriver',
            'fossil' => 'Composer\Repository\Vcs\FossilDriver',
            // svn must be last because identifying a subversion server for sure is practically impossible
            'svn' => 'Composer\Repository\Vcs\SvnDriver',
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
     * @param string $classOrType
     * @param array $repoConfig
     * @param IOInterface $io
     * @param Config $config
     * @param array $options
     *
     * @return VcsDriver|VcsDriverInterface
     */
    public function createDriver(array $repoConfig, IOInterface $io, Config $config, string $classOrType = null, array $options = [])
    {
        $process = $this->createProcessExecutor($io);

        $driver = null;
        if ($classOrType && class_exists($classOrType)) {
            $driver = new $classOrType($repoConfig, $io, $config, $process);
            return $driver;
        }

        if (null === $driver && null !== $classOrType && isset($this->drivers[$classOrType])) {
            $class = $this->drivers[$classOrType];
            $driver = new $class($repoConfig, $io, $config);
            return $driver;
        }

        if (null === $driver && isset($options['url'])) {
            foreach ($this->drivers as $driverClass) {
                if ($driverClass::supports($io, $config, $options['url'])) {
                    $driver = new $driverClass($repoConfig, $io, $config, $process);
                    break;
                }
            }
        }

        if (null === $driver && isset($options['url'])) {
            foreach ($this->drivers as $driverClass) {
                if ($driverClass::supports($io, $config, $options['url'], true)) {
                    $driver = new $driverClass($repoConfig, $io, $config, $process);
                    break;
                }
            }
        }

        if ($driver instanceof VcsDriverInterface) {
            $driver->initialize();
        }

        return $driver;
    }

    protected function createProcessExecutor(IOInterface $io)
    {
        return new ProcessExecutor($io);
    }
}
