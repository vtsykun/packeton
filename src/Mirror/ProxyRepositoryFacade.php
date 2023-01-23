<?php

declare(strict_types=1);

namespace Packeton\Mirror;

use Packeton\Mirror\Exception\MetadataNotFoundException;
use Packeton\Mirror\Model\JsonMetadata;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\Model\ProxyRepositoryInterface;
use Packeton\Mirror\Service\SyncProviderService;

class ProxyRepositoryFacade implements ProxyRepositoryInterface
{
    protected ?ProxyOptions $opt;

    public function __construct(
        protected RemoteProxyRepository|ProxyRepositoryInterface $repository,
        protected SyncProviderService                            $syncService,
    ) {
        if ($repository instanceof RemoteProxyRepository) {
            $this->opt = $repository->getConfig();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findPackageMetadata(string $nameOrUri): JsonMetadata
    {
        if (null !== $this->opt) {
            [$package, ] = \explode('$', $nameOrUri);
            $this->checkIfAvailablePackage($package);
        }

        return $this->fetch(__FUNCTION__, func_get_args(), function () use ($nameOrUri) {
            $this->lazyFetchPackageMetadata($nameOrUri);
            throw new MetadataNotFoundException('Not found');
        });
    }

    /**
     * {@inheritdoc}
     */
    public function findProviderMetadata(string $nameOrUri): JsonMetadata
    {
        return $this->fetch(__FUNCTION__, func_get_args(), function () {
            throw new MetadataNotFoundException('Not found');
        });
    }

    /**
     * {@inheritdoc}
     */
    public function rootMetadata(): JsonMetadata
    {
        return $this->fetch(__FUNCTION__, [], function () {
            try {
                $meta = $this->syncService->loadRootComposer($this->repository);
            } catch (\Exception $e) {
                throw new MetadataNotFoundException($e->getMessage(), 0, $e);
            }

            $this->repository->dumpRootMeta($meta);
            return $meta;
        });
    }

    protected function fetch(string $key, array $args = [], callable $fn = null)
    {
        $meta = $this->repository->{$key}(...$args);

        if (null === $meta && (null === $this->opt || !$this->opt->isLazy())) {
            throw new MetadataNotFoundException($key === 'rootMetadata' ? 'This is not a lazy proxy, so for fetch metadata need to sync it in background' : 'Metadata not found');
        }

        return $meta ? : $fn($args);
    }

    protected function lazyFetchPackageMetadata(string $nameOrUri)
    {
        [$package, ] = \explode('$', $nameOrUri);

        $http = $this->syncService->initHttpDownloader($this->opt);
        if ($metadataUrl = $this->opt->getMetadataV2Url($package)) {
            $response = $http->get($metadataUrl);
            if ($response->getStatusCode() !== 200) {
                throw new MetadataNotFoundException($response->getBody());
            }

            $metadata = $response->decodeJson();
        } elseif ($metadataUrl = $this->opt->getMetadataV1Url($package)) {

            // Try to search in included providers
        }
    }

    protected function checkIfAvailablePackage(string $package): void
    {

    }
}
