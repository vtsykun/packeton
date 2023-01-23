<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Packeton\Mirror\Exception\MetadataNotFoundException;
use Packeton\Mirror\ProxyRepositoryFacade;
use Packeton\Mirror\ProxyRepositoryRegistry;

class ComposeProxyRegistry
{
    private array $factoryArgs;

    public function __construct(
        protected ProxyRepositoryRegistry $proxyRegistry,
        SyncProviderService $syncService,
    ) {
        $this->factoryArgs = array_slice(func_get_args(), 1);
    }

    public function createRepository(string $name): ProxyRepositoryFacade
    {
        try {
            $repo = $this->proxyRegistry->getRepository($name);
        } catch (\InvalidArgumentException $e) {
            throw new MetadataNotFoundException('Provider does not exists', 0, $e);
        }

        return new ProxyRepositoryFacade($repo, ...$this->factoryArgs);
    }

    public function createProxyDownloadManager(string $name)
    {
    }
}
