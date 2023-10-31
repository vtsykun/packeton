<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

interface ProxyRepositoryInterface
{
    /**
     * Base package metadata.
     *
     * @param string $nameOrUri
     * @param int $modifiedSince
     * @return JsonMetadata|null
     */
    public function findPackageMetadata(string $nameOrUri, int $modifiedSince = null): ?JsonMetadata;

    /**
     * Provider include metadata
     *
     * @param string $nameOrUri
     * @param int $modifiedSince
     *
     * @return JsonMetadata|null
     */
    public function findProviderMetadata(string $nameOrUri, int $modifiedSince = null): ?JsonMetadata;

    /**
     * Get composer root
     *
     * @param int $modifiedSince
     * @return JsonMetadata|null
     */
    public function rootMetadata(int $modifiedSince = null): ?JsonMetadata;
}
