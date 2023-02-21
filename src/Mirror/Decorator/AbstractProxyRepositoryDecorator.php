<?php

declare(strict_types=1);

namespace Packeton\Mirror\Decorator;

use Packeton\Mirror\Exception\MetadataNotFoundException;
use Packeton\Mirror\Model\JsonMetadata;
use Packeton\Mirror\Model\StrictProxyRepositoryInterface;

abstract class AbstractProxyRepositoryDecorator implements StrictProxyRepositoryInterface
{
    public function findPackageMetadata(string $nameOrUri, int $modifiedSince = null): JsonMetadata
    {
        throw new MetadataNotFoundException('Not found');
    }

    public function findProviderMetadata(string $nameOrUri, int $modifiedSince = null): JsonMetadata
    {
        throw new MetadataNotFoundException('Not found');
    }

    public function rootMetadata(int $modifiedSince = null): JsonMetadata
    {
        throw new MetadataNotFoundException('Not found');
    }
}
