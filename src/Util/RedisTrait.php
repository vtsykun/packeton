<?php

declare(strict_types=1);

namespace Packeton\Util;

trait RedisTrait
{
    private function hSet(string $key, string $hashKey, mixed $value)
    {
        if (($result = $this->redis->hSet($key, $hashKey, $value)) === false) {
            if (false !== $this->redis->get($key)) {
                $this->redis->del($key);
                $result = $this->redis->hSet($key, $hashKey, $value);
            }
        }

        $result;
    }
}
