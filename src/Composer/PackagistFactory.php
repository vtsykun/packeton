<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Composer;

use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Packagist\WebBundle\Composer\Util\ProcessExecutor;
use Packagist\WebBundle\Entity\SshCredentials;

class PackagistFactory
{
    protected $tmpDir;
    protected $githubNoApi;
    protected $repositoryFactory;

    public function __construct(VcsRepositoryFactory $repositoryFactory, string $tmpDir = null, $githubNoApi = null)
    {
        $this->repositoryFactory = $repositoryFactory;
        $this->tmpDir = $tmpDir ?: sys_get_temp_dir();
        $this->githubNoApi = (bool) $githubNoApi;
    }

    /**
     * @param SshCredentials|null $credentials
     * @return \Composer\Config
     */
    public function createConfig(SshCredentials $credentials = null)
    {
        $config = Factory::createConfig();

        if (null !== $credentials) {
            $uid = @getmyuid();
            $credentialsFile = rtrim($this->tmpDir, '/') . '/packagist_priv_key_' . $credentials->getId() . '_' . $uid;
            if (!file_exists($credentialsFile)) {
                file_put_contents($credentialsFile, $credentials->getKey());
                chmod($credentialsFile, 0600);
            }
            putenv("GIT_SSH_COMMAND=ssh -o IdentitiesOnly=yes -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -i $credentialsFile");
            ProcessExecutor::inheritEnv(['GIT_SSH_COMMAND']);

            $config->merge(['config' => ['ssh-key-file' => $credentialsFile]]);
        } else {
            ProcessExecutor::inheritEnv([]);
            putenv('GIT_SSH_COMMAND');
        }

        return $config;
    }

    /**
     * @param string $url
     * @param IOInterface|null $io
     * @param Config|null $config
     * @param SshCredentials|null $credentials
     * @param array $repoConfig
     *
     * @return Repository\VcsRepository
     */
    public function createRepository(string $url, IOInterface $io = null, Config $config = null, SshCredentials $credentials = null, array $repoConfig = [])
    {
        $io = $io ?: new NullIO();
        if (null === $config) {
            $config = $this->createConfig($credentials);
            $io->loadConfiguration($config);
        }

        $repoConfig['url'] = $url;
        if (null !== $credentials || true === $this->githubNoApi) {
            // Disable API if used ssh key
            $repoConfig['no-api'] = true;
        }

        return $this->repositoryFactory->create($repoConfig, $io, $config);
    }
}
