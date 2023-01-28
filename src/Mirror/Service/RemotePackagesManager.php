<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

class RemotePackagesManager
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $repo,
    ) {
    }

    public function markEnable(string $name): void
    {
        $this->redis->zadd("repo:{$this->repo}:enabled", time(), $name);
    }

    public function allEnabled(): array
    {
        return $this->redis->zRange("repo:{$this->repo}:enabled", 0, -1) ?: [];
    }

    public function allApproved(): array
    {
        return $this->redis->zRange("repo:{$this->repo}:approve", 0, -1) ?: [];
    }

    public function markApprove(string $name): void
    {
        $this->redis->zadd("repo:{$this->repo}:approve", time(), $name);
        $this->redis->zadd("repo:{$this->repo}:enabled", time(), $name);
    }

    public function markDisable(string $name): void
    {
        $this->redis->zrem("repo:{$this->repo}:enabled", $name);
    }

    public function removeApprove(string $name): void
    {
        $this->redis->zrem("repo:{$this->repo}:approve", $name);
        $this->redis->zrem("repo:{$this->repo}:enabled", $name);
    }
}
