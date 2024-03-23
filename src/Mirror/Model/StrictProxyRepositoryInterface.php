<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

interface StrictProxyRepositoryInterface extends ProxyRepositoryInterface
{
    /**
     * {@inheritDoc}
     * @throw \Packeton\Mirror\Exception\MetadataNotFoundException
     */
    public function rootMetadata(?int $modifiedSince = null): JsonMetadata;

    /**
     * {@inheritDoc}
     * @throw \Packeton\Mirror\Exception\MetadataNotFoundException
     */
    public function findProviderMetadata(string $name, ?int $modifiedSince = null): JsonMetadata;

    /**
     * {@inheritDoc}
     * @throw \Packeton\Mirror\Exception\MetadataNotFoundException
     */
    public function findPackageMetadata(string $name, ?int $modifiedSince = null): JsonMetadata;
}
