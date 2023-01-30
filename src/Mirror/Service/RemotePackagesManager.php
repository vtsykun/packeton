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

    public function getSettings(): array
    {
        $settings = $this->redis->get("repo:{$this->repo}:settings");
        $settings = $settings ? \json_decode($settings, true) : [];

        return [
            'strict_mirror' => (bool)($settings['strict_mirror'] ?? false),
            'enabled_sync' => (bool)($settings['enabled_sync'] ?? true),
        ];
    }

    public function setSettings(array $settings): void
    {
        $this->redis->set("repo:{$this->repo}:settings", \json_encode($settings));
    }

    public function isMinoring(): bool
    {
        return $this->getSettings()['strict_mirror'];
    }

    public function isAutoSync(): bool
    {
        return $this->getSettings()['enabled_sync'];
    }

    public function markEnable(string $name): void
    {
        $this->redis->zadd("repo:{$this->repo}:enabled", time(), $name);
    }

    public function getEnabled(): array
    {
        return $this->redis->zRange("repo:{$this->repo}:enabled", 0, -1) ?: [];
    }

    public function getApproved(): array
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
