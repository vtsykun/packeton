<?php

declare(strict_types=1);

namespace Packeton\Mirror;

/**
 * Allow to merge package metadata into one
 */
class ChainProxyRepository extends AbstractProxyRepository
{
    public function __construct(protected iterable $proxies)
    {
    }
}
