<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

interface StrictProxyRepositoryInterface extends ProxyRepositoryInterface
{
    /**
     * @return JsonMetadata
     *
     * @throw \Packeton\Mirror\Exception\MetadataNotFoundException
     */
    public function rootMetadata(): JsonMetadata;

    /**
     * @param string $name
     * @return JsonMetadata
     *
     * @throw \Packeton\Mirror\Exception\MetadataNotFoundException
     */
    public function findProviderMetadata(string $name): JsonMetadata;

    /**
     * @param string $name
     * @return JsonMetadata
     *
     * @throw \Packeton\Mirror\Exception\MetadataNotFoundException
     */
    public function findPackageMetadata(string $name): JsonMetadata;
}
