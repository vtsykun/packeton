<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

interface ProxyRepositoryInterface
{
    /**
     * Base package metadata.
     *
     * @param string $nameOrUri
     * @return JsonMetadata|null
     */
    public function findPackageMetadata(string $nameOrUri): ?JsonMetadata;

    /**
     * Provider include metadata
     *
     * @param string $nameOrUri
     * @return JsonMetadata|null
     */
    public function findProviderMetadata(string $nameOrUri): ?JsonMetadata;

    /**
     * Get composer root
     *
     * @return JsonMetadata|null
     */
    public function rootMetadata(): ?JsonMetadata;
}
