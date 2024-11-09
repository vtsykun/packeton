<?php

declare(strict_types=1);

namespace Packeton\Composer;

use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Package\Archiver\ArchiveManager;
use Composer\Util\Loop;
use Composer\Package\Archiver as CA;
use Packeton\Composer\Repository\PacketonRepositoryInterface;
use Packeton\Composer\Util\ConfigFactory;
use Packeton\Composer\Util\ProcessExecutor;
use Packeton\Entity\OAuthIntegration;
use Packeton\Integrations\IntegrationRegistry;
use Packeton\Integrations\Model\AppUtils;
use Packeton\Model\CredentialsInterface;
use Packeton\Package\RepTypes;
use Packeton\Util\SshKeyHelper;

class PackagistFactory
{
    protected $tmpDir;
    protected $factory;

    public function __construct(
        protected PacketonRepositoryFactory $repositoryFactory,
        protected IntegrationRegistry $integrations,
        protected bool $githubNoApi = true,
        ?string $composerHome = null
    ) {
        $this->tmpDir = $composerHome ?: sys_get_temp_dir();
        if ($composerHome) {
            ConfigFactory::setHomeDir($composerHome);
        }

        $this->factory = new ConfigFactory();
    }

    /**
     * @param IOInterface $io
     * @param PacketonRepositoryInterface $repository
     *
     * @return \Composer\Downloader\DownloadManager
     */
    public function createDownloadManager(IOInterface $io, PacketonRepositoryInterface $repository): DownloadManager
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
     * @param PacketonRepositoryInterface $repository
     * @param DownloadManager|null $dm
     *
     * @return \Composer\Package\Archiver\ArchiveManager|Archiver\ArchiveManager
     */
    public function createArchiveManager(IOInterface $io, PacketonRepositoryInterface $repository, ?DownloadManager $dm = null): ArchiveManager
    {
        $dm ??= $this->createDownloadManager($io, $repository);
        $repoConfig = $repository->getRepoConfig();

        $am = new Archiver\ArchiveManager(
            $dm,
            new Loop($repository->getHttpDownloader(), $repository->getProcessExecutor()),
            $repository->getConfig(),
            $repository->getProcessExecutor(),
            $repoConfig,
            $io,
            $repository->getHttpDownloader()
        );

        if (class_exists(\ZipArchive::class)) {
            $am->addArchiver(new CA\ZipArchiver);
        }
        if (class_exists(\Phar::class)) {
            $am->addArchiver(new CA\PharArchiver);
        }

        if (isset($repoConfig['subDirectory']) && $repoConfig['subDirectory']) {
            $am->setSubDirectory($repoConfig['subDirectory']);
        }

        return $am;
    }

    /**
     * @param CredentialsInterface|null $credentials
     * @return \Composer\Config
     */
    public function createConfig(?CredentialsInterface $credentials = null)
    {
        $config = ConfigFactory::createConfig();

        ProcessExecutor::inheritEnv([]);
        putenv('GIT_SSH_COMMAND');

        if (null !== $credentials) {
            if ($credentials->getComposerConfig()) {
                $config->merge(['config' => $credentials->getComposerConfig()]);
            }

            if ($key = $credentials->getKey()) {
                $uid = @getmyuid();
                $key = SshKeyHelper::trimKey($key);
                $keyId = (method_exists($credentials, 'getId') ? $credentials->getId() : '') . '_'. substr(sha1($key), 0, 6);
                $credentialsFile = rtrim($this->tmpDir, '/') . '/packeton_priv_key_' . $keyId . '_' . $uid;
                if (!file_exists($credentialsFile)) {
                    file_put_contents($credentialsFile, $key);
                    chmod($credentialsFile, 0600);
                }
                putenv("GIT_SSH_COMMAND=ssh -o IdentitiesOnly=yes -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -i $credentialsFile");
                ProcessExecutor::inheritEnv(['GIT_SSH_COMMAND']);

                $config->merge(['config' => ['ssh-key-file' => $credentialsFile]]);
            } else if ($credentials->getPrivkeyFile()) {
                putenv("GIT_SSH_COMMAND=ssh -o IdentitiesOnly=yes -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -i {$credentials->getPrivkeyFile()}");
                ProcessExecutor::inheritEnv(['GIT_SSH_COMMAND']);
            }
        }

        return $config;
    }

    /**
     * @param string $url
     * @param IOInterface|null $io
     * @param Config|null $config
     * @param CredentialsInterface|null $credentials
     * @param array $repoConfig
     *
     * @return PacketonRepositoryInterface
     */
    public function createRepository(string $url, ?IOInterface $io = null, ?Config $config = null, ?CredentialsInterface $credentials = null, array $repoConfig = []): PacketonRepositoryInterface
    {
        $io ??= new NullIO();
        if (null === $config) {
            $config = $this->createConfig($credentials);
            $io->loadConfiguration($config);
        }
        $oauth2 = $repoConfig['oauth2'] ?? null;
        if ($oauth2 instanceof OAuthIntegration && $this->integrations->has($oauth2->getAlias())) {
            $app = $this->integrations->get($oauth2->getAlias());

            try {
                $app->authenticateIO($oauth2, $io, $config, $url);
            } catch (\Throwable $e) {
                $msg = AppUtils::castError($e, $oauth2, true);
                throw new \RuntimeException("Unable to Composer authenticate. \n$msg", $e->getCode(), $e);
            }
        }

        $repoConfig['url'] = $url;
        $repoType = $repoConfig['repoType'] ?? null;
        if (isset($repoConfig['subDirectory']) || $repoType === RepTypes::MONO_REPO) {
            $repoConfig['driver'] = 'git-tree';
        }
        if ($repoType === RepTypes::ASSET) {
            $repoConfig['driver'] = 'asset';
        }

        if (null !== $credentials || true === $this->githubNoApi) {
            // Disable API if used ssh key
            if (null === $credentials?->getComposerConfigOption('use-github-api') || !empty($credentials?->getKey())) {
                $repoConfig['no-api'] = true;
            }
        }

        if ($config->has('_no_api')) {
            $repoConfig['no-api'] = $config->get('_no_api');
        }
        if ($config->has('_driver')) {
            $repoConfig['driver'] = $config->get('_driver');
        }

        return $this->repositoryFactory->create($repoConfig, $io, $config, $repoType);
    }
}
