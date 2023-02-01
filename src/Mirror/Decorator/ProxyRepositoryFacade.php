<?php

declare(strict_types=1);

namespace Packeton\Mirror\Decorator;

use Composer\Downloader\TransportException;
use Packeton\Composer\MetadataMinifier;
use Packeton\Mirror\Exception\MetadataNotFoundException;
use Packeton\Mirror\Model\HttpMetadataTrait;
use Packeton\Mirror\Model\JsonMetadata;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\RemoteProxyRepository;
use Packeton\Mirror\Service\RemotePackagesManager;
use Packeton\Mirror\Service\SyncProviderService;

class ProxyRepositoryFacade extends AbstractProxyRepositoryDecorator
{
    use HttpMetadataTrait;

    protected ProxyOptions $config;
    protected RemotePackagesManager $rmp;

    public function __construct(
        protected RemoteProxyRepository $repository,
        protected SyncProviderService $syncService,
        protected MetadataMinifier $metadataMinifier
    ) {
        $this->config = $this->repository->getConfig();
        $this->rmp = $this->repository->getPackageManager();
    }

    /**
     * {@inheritdoc}
     */
    public function findPackageMetadata(string $nameOrUri): JsonMetadata
    {
        [$package, ] = \explode('$', $nameOrUri);

        $metadata = $this->fetch(__FUNCTION__, func_get_args(), function () use ($package) {
            $metadata = $this->lazyFetchPackageMetadata($package);

            $this->repository->touchRoot();
            $this->repository->dumpPackage($package, $metadata);
            return new JsonMetadata(\json_encode($metadata, \JSON_UNESCAPED_SLASHES));
        });

        if (null !== $metadata) {
            $this->rmp->markEnable($package);
        }

        return $metadata;
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

        if (null === $meta && !$this->config->isLazy()) {
            throw new MetadataNotFoundException($key === 'rootMetadata' ? 'This is not a lazy proxy, so for fetch metadata need to sync it in background' : 'Metadata not found');
        }

        return $meta ? : $fn($args);
    }

    protected function lazyFetchPackageMetadata(string $package): string|array
    {
        $http = $this->syncService->initHttpDownloader($this->config);

        if ($this->config->hasV2Api()) {
            $queue = new \SplQueue();
            try {
                $apiUrl =  $this->config->getMetadataV2Url();
                $this->requestMetadataVia2($http, [$package], $apiUrl, fn ($name, $meta) => $queue->enqueue($meta));
            } catch (TransportException $exception) {
                throw new MetadataNotFoundException("The package $package is not exist. Remote proxy status: {$exception->getStatusCode()}");
            }

            if (!empty($meta = $queue->dequeue())) {
                return $meta;
            }
        } elseif ($metadataUrl = $this->config->getMetadataV1Url($package)) {
            // TODO: Try to search in included providers
        }

        throw new MetadataNotFoundException('not found');
    }
}
