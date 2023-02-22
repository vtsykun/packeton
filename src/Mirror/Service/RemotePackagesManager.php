<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Packeton\Mirror\Model\ApprovalRepoInterface;

class RemotePackagesManager implements ApprovalRepoInterface
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
            'disable_v2' => (bool)($settings['disable_v2'] ?? false),
        ];
    }

    public function setSettings(array $settings): void
    {
        $this->redis->set("repo:{$this->repo}:settings", \json_encode($settings));
    }

    /**
     * {@inheritdoc}
     */
    public function requireApprove(): bool
    {
        return $this->getSettings()['strict_mirror'];
    }

    public function isAutoSync(): bool
    {
        return $this->getSettings()['enabled_sync'];
    }

    public function markEnable(string $name): void
    {
        $this->redis->sAdd("repo:{$this->repo}:enabled", $name);
    }

    public function getEnabled(): array
    {
        return $this->redis->sMembers("repo:{$this->repo}:enabled") ?: [];
    }

    /**
     * {@inheritdoc}
     */
    public function getApproved(): array
    {
        return $this->redis->sMembers("repo:{$this->repo}:approve") ?: [];
    }

    /**
     * {@inheritdoc}
     */
    public function markApprove(string $name): void
    {
        $this->redis->sAdd("repo:{$this->repo}:approve", $name);
        $this->redis->sAdd("repo:{$this->repo}:enabled", $name);
    }

    public function markDisable(string $name): void
    {
        $this->redis->sRem("repo:{$this->repo}:enabled", $name);
    }

    /**
     * {@inheritdoc}
     */
    public function removeApprove(string $name): void
    {
        $this->redis->sRem("repo:{$this->repo}:approve", $name);
        $this->redis->sRem("repo:{$this->repo}:enabled", $name);
    }
}
