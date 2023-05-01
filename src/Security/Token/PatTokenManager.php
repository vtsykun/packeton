<?php

declare(strict_types=1);

namespace Packeton\Security\Token;

class PatTokenManager
{
    private static $key = 'pat:tokens:stats';

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function setLastUsage(int $keyId, array $extra)
    {
        $extra['last_used'] = date('Y-m-d H:i:s');
        $this->redis->hSet(self::$key, "info:$keyId", json_encode($extra));
        $this->redis->hIncrBy(self::$key, "usage:$keyId", 1);
    }

    public function getStats(int $keyId): array
    {
        $usage = $this->redis->hGet(self::$key, "usage:$keyId") ?: 0;
        $stats = $this->redis->hGet(self::$key, "info:$keyId");
        $stats = $stats ? json_decode($stats, true) : [];
        $stats += ['usage' => $usage, 'ua' => null, 'ip' => null, 'last_used' => null];

        return $stats;
    }
}
