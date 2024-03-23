<?php

declare(strict_types=1);

namespace Packeton\Mirror;

use Packeton\Mirror\Model\JsonMetadata;
use Packeton\Mirror\Model\ProxyInfoInterface;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\Model\ProxyRepositoryInterface;

abstract class AbstractProxyRepository implements ProxyRepositoryInterface, ProxyInfoInterface
{
    protected array $repoConfig = [];
    protected ?ProxyOptions $proxyOptions;

    /**
     * {@inheritdoc}
     */
    public function getConfig(): ProxyOptions
    {
        return $this->proxyOptions ??= new ProxyOptions(
            $this->repoConfig
            + ['root' => $this->getRootMetadataInfo()]
            + ['stats' => $this->getStats()]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findPackageMetadata(string $name, ?int $modifiedSince = null): ?JsonMetadata
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function findProviderMetadata(string $name, ?int $modifiedSince = null): ?JsonMetadata
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function rootMetadata(?int $modifiedSince = null): ?JsonMetadata
    {
        return null;
    }

    public function getStats(): array
    {
        return [];
    }

    public function setStats(array $stats = []): void
    {
    }

    public function resetProxyOptions(): void
    {
        $this->proxyOptions = null;
    }

    protected function getRootMetadataInfo(): array
    {
        if ($meta = $this->rootMetadata()) {
            $data = $meta->decodeJson();
            $data['modified_since'] = $meta->lastModified()->getTimestamp();
            return $data;
        }

        return [];
    }
}
