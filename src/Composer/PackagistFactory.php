<?php

declare(strict_types=1);

namespace Packeton\Composer;

use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Package\Archiver\ArchiveManager;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Loop;
use Packeton\Composer\Repository\VcsRepository;
use Packeton\Composer\Util\ConfigFactory;
use Packeton\Composer\Util\ProcessExecutor;
use Packeton\Entity\SshCredentials;

class PackagistFactory
{
    protected $tmpDir;
    protected $githubNoApi;
    protected $repositoryFactory;
    protected $factory;

    public function __construct(VcsRepositoryFactory $repositoryFactory, bool $githubNoApi = null, string $composerHome = null)
    {
        $this->repositoryFactory = $repositoryFactory;
        $this->tmpDir = $composerHome ?: sys_get_temp_dir();

        $this->githubNoApi = $githubNoApi;
        if ($composerHome) {
            ConfigFactory::setHomeDir($composerHome);
        }

        $this->factory = new ConfigFactory();
    }

    /**
     * @param IOInterface $io
     * @param RepositoryInterface|VcsRepository $repository
     *
     * @return \Composer\Downloader\DownloadManager
     */
    public function createDownloadManager(IOInterface $io, RepositoryInterface $repository): DownloadManager
    {
        return $this->factory->createDownloadManager(
            $io,
            $repository->getConfig(),
            $repository->getHttpDownloader(),
            $repository->getProcessExecutor()
        );
    }

    /**
     * @param Config $config
     * @param RepositoryInterface|VcsRepository $repository
     * @param DownloadManager|null $dm
     *
     * @return \Composer\Package\Archiver\ArchiveManager
     */
    public function createArchiveManager(IOInterface $io, RepositoryInterface $repository, DownloadManager $dm = null): ArchiveManager
    {
        $dm = $dm ?: $this->createDownloadManager($io, $repository);

        return $this->factory->createArchiveManager(
            $repository->getConfig(),
            $dm,
            new Loop($repository->getHttpDownloader(), $repository->getProcessExecutor())
        );
    }

    /**
     * @param SshCredentials|null $credentials
     * @return \Composer\Config
     */
    public function createConfig(SshCredentials $credentials = null)
    {
        $config = ConfigFactory::createConfig();

        ProcessExecutor::inheritEnv([]);
        putenv('GIT_SSH_COMMAND');

        if (null !== $credentials) {
            if ($credentials->getComposerConfig()) {
                $config->merge(['config' => $credentials->getComposerConfig()]);
            }

            if ($credentials->getKey()) {
                $uid = @getmyuid();
                $credentialsFile = rtrim($this->tmpDir, '/') . '/packeton_priv_key_' . $credentials->getId() . '_' . $uid;
                if (!file_exists($credentialsFile)) {
                    file_put_contents($credentialsFile, $credentials->getKey());
                    chmod($credentialsFile, 0600);
                }
                putenv("GIT_SSH_COMMAND=ssh -o IdentitiesOnly=yes -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -i $credentialsFile");
                ProcessExecutor::inheritEnv(['GIT_SSH_COMMAND']);

                $config->merge(['config' => ['ssh-key-file' => $credentialsFile]]);
            }
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
            if (!$config->has('use-github-api') || !empty($credentials?->getKey())) {
                $repoConfig['no-api'] = true;
            }
        }

        return $this->repositoryFactory->create($repoConfig, $io, $config);
    }
}
