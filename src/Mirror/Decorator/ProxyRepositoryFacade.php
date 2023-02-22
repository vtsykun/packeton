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
    public function findPackageMetadata(string $nameOrUri, int $modifiedSince = null): JsonMetadata
    {
        [$package, ] = \explode('$', $nameOrUri);

        $metadata = $this->fetch(__FUNCTION__, func_get_args(), function () use ($package) {
            $metadata = $this->lazyFetchPackageMetadata($package);

            $this->repository->touchRoot();
            $this->repository->dumpPackage($package, $metadata);
            return new JsonMetadata(\json_encode($metadata, \JSON_UNESCAPED_SLASHES));
        });

        $this->rmp->markEnable($package);

        return $metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function findProviderMetadata(string $nameOrUri, int $modifiedSince = null): JsonMetadata
    {
        return $this->fetch(__FUNCTION__, func_get_args(), function () {
            throw new MetadataNotFoundException('Not found');
        });
    }

    /**
     * {@inheritdoc}
     */
    public function rootMetadata(int $modifiedSince = null): JsonMetadata
    {
        $metadata = $this->fetch(__FUNCTION__, [], function () {
            try {
                $meta = $this->syncService->loadRootComposer($this->repository);
            } catch (\Exception $e) {
                throw new MetadataNotFoundException($e->getMessage(), 0, $e);
            }

            $this->repository->dumpRootMeta($meta);
            return $meta;
        });

        // set default available_packages if repository is small.
        if ($available = $this->repository->getConfig()->getStats('available_packages')) {
            $options = $metadata->getOptions();
            if (empty($options->getAvailablePackages()) && empty($options->getAvailablePatterns())) {
                $metadata->setOption('available_packages', $available);
            }
        }

        return $metadata;
    }

    protected function fetch(string $key, array $args = [], callable $fn = null): JsonMetadata
    {
        $meta = $this->repository->{$key}(...$args);

        if (null === $meta && (!$this->config->isLazy() || null === $fn)) {
            throw new MetadataNotFoundException($key === 'rootMetadata' ? 'This is not a lazy proxy, so for fetch metadata need to sync it in background' : 'Metadata not found');
        }

        return $meta ? : $fn($args);
    }

    protected function lazyFetchPackageMetadata(string $package): string|array
    {
        $http = $this->syncService->initHttpDownloader($this->config);

        $queue = new \SplQueue();
        $reject = static function (\Throwable $e) use ($package) {
            if ($e instanceof TransportException) {
                throw new MetadataNotFoundException("The package $package not found. Remote proxy status: {$e->getStatusCode()}");
            }

            throw new MetadataNotFoundException("The package $package errored. Unexpected exception " . $e->getMessage());
        };

        if ($this->config->hasV2Api()) {
            $apiUrl =  $this->config->getMetadataV2Url();
            $this->requestMetadataVia2($http, [$package], $apiUrl, fn ($name, array $meta) => $queue->enqueue($meta), $reject);

        } elseif ($apiUrl = $this->config->getMetadataV1Url($package)) {
            $this->requestMetadataVia1($http, [$package], $apiUrl, fn ($name, array $meta) => $queue->enqueue($meta), $reject, $this->repository->lookupAllProviders());
        }

        if (!empty($meta = $queue->dequeue())) {
            return $meta;
        }

        throw new MetadataNotFoundException('Not found');
    }
}
