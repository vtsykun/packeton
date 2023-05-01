<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Packeton\Composer\MetadataMinifier;
use Packeton\Mirror\Decorator\ProxyRepositoryACLDecorator;
use Packeton\Mirror\Decorator\ProxyRepositoryFacade;
use Packeton\Mirror\Exception\MetadataNotFoundException;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\Model\StrictProxyRepositoryInterface as PRI;
use Packeton\Mirror\ProxyRepositoryRegistry;
use Packeton\Mirror\RemoteProxyRepository;
use Packeton\Model\ProviderManager;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class ComposeProxyRegistry
{
    public function __construct(
        protected ProxyRepositoryRegistry $proxyRegistry,
        protected SyncProviderService $syncService,
        protected MetadataMinifier $metadataMinifier,
        protected ProviderManager $providerManager,
        protected TokenStorageInterface $tokenStorage,
    ) {
    }

    public function createRepository(string $name): PRI
    {
        $repo = $this->getRemoteProxyRepository($name);

        return new ProxyRepositoryFacade($repo, $this->syncService, $this->metadataMinifier);
    }

    public function getProxyConfig(string $name): ProxyOptions
    {
        return $this->getRemoteProxyRepository($name)->getConfig();
    }

    public function createACLAwareRepository(string $name): PRI
    {
        $repo = $this->getRemoteProxyRepository($name);

        return new ProxyRepositoryACLDecorator(
            $this->createRepository($name),
            $repo->getPackageManager(),
            $this->tokenStorage,
            $repo,
            $repo->getConfig()->getAvailablePackages(),
            $repo->getConfig()->getAvailablePatterns(),
            $this->providerManager->getPackageNames(),
        );
    }

    public function getProxyDownloadManager(string $name): ZipballDownloadManager
    {
        $repo = $this->getRemoteProxyRepository($name);

        return $repo->getDownloadManager();
    }

    protected function getRemoteProxyRepository(string $name)
    {
        try {
            $repo = $this->proxyRegistry->getRepository($name);
            if (!$repo instanceof RemoteProxyRepository) {
                throw new MetadataNotFoundException('Provider does not exists');
            }
        } catch (\InvalidArgumentException $e) {
            throw new MetadataNotFoundException('Provider does not exists', 0, $e);
        }

        return $repo;
    }
}
