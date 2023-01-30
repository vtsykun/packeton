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
            + ['root' => $this->rootMetadata()?->decodeJson()]
            + ['stats' => $this->getStats()]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findPackageMetadata(string $name): ?JsonMetadata
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function findProviderMetadata(string $name): ?JsonMetadata
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function rootMetadata(): ?JsonMetadata
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
}
