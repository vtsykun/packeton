<?php

declare(strict_types=1);

namespace Packeton\Composer\Cache;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RequestContext;
use Symfony\Contracts\Cache\CacheInterface;

class MetadataCache
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CacheInterface $packagesCachePool,
        private readonly RequestContext $requestContext,
        private readonly int $maxTtl = 1800 // TTL default / 2
    ) {
    }

    public function get(string $key, callable $callback, ?int $lastModify = null, ?callable $needClearCache = null)
    {
        // Use host key to prevent Cache Poisoning attack, if dist URL generated dynamic.
        // But for will protection must be used trusted_hosts
        $httpKey = $this->requestStack->getMainRequest()?->getSchemeAndHttpHost();

        $cacheKey = sha1($key . $httpKey . $this->requestContext->getBaseUrl());
        $item = $this->packagesCachePool->getItem($cacheKey);
        @[$ctime, $data] = $item->get();

        $needRefresh = false;
        if (null !== $lastModify) {
            $needRefresh = $ctime < $lastModify || $ctime + $this->maxTtl < time();
        }
        if (null !== $needClearCache) {
            $needRefresh = $needRefresh || $needClearCache($data);
        }

        if (!$item->isHit() || $needRefresh || empty($data)) {
            $data = $callback($item);

            $item->set([time(), $data]);
            $this->packagesCachePool->save($item);
        }

        return $data;
    }

    public function delete(string $key): bool
    {
        return $this->packagesCachePool->delete($key);
    }
}
