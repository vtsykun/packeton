<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Packeton\Mirror\Model\ApprovalRepoInterface;

class RemotePackagesManager implements ApprovalRepoInterface
{
    private string $patchKey;
    private string $settingKey;
    private string $enabledKey;
    private string $approvedKey;

    public function __construct(
        private readonly \Redis $redis,
        private readonly string $repo,
    ) {
        $this->patchKey = "repo:{$this->repo}:patches";
        $this->settingKey = "repo:{$this->repo}:settings";
        $this->enabledKey = "repo:{$this->repo}:enabled";
        $this->approvedKey = "repo:{$this->repo}:approve";
    }

    public function getSettings(): array
    {
        $settings = $this->redis->get($this->settingKey);
        $settings = $settings ? \json_decode($settings, true) : [];

        return [
            'strict_mirror' => (bool)($settings['strict_mirror'] ?? false),
            'enabled_sync' => (bool)($settings['enabled_sync'] ?? true),
            'disable_v2' => (bool)($settings['disable_v2'] ?? false),
        ];
    }

    public function setSettings(array $settings): void
    {
        $this->redis->set($this->settingKey, \json_encode($settings));
    }

    public function patchMetadata(string $package, string $version, string $strategy, array $metadata): void
    {
        $settings = $this->getPatchMetadata();
        $settings[$package][$version] = [$strategy, $metadata];
        $this->redis->set($this->patchKey, \json_encode($settings));
    }

    public function getPatchMetadata(?string $package = null): array
    {
        $settings = $this->redis->get($this->patchKey);
        $settings = $settings ? \json_decode($settings, true) : [];

        return $package ? ($settings[$package] ?? []) : $settings;
    }

    public function unsetPatchMetadata(?string $package = null, ?string $version = null): void
    {
        $settings = $this->getPatchMetadata();
        if ($package) {
            if ($version) {
                unset($settings[$package][$version]);
            } else {
                unset($settings[$package]);
            }
        } else {
            $settings = [];
        }

        $this->redis->set($this->patchKey, \json_encode($settings));
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
        $this->redis->sAdd($this->enabledKey, $name);
    }

    public function getEnabled(): array
    {
        return $this->redis->sMembers($this->enabledKey) ?: [];
    }

    public function isEnabled(string $name): bool
    {
        return (bool) $this->redis->sIsMember($this->enabledKey, strtolower($name));
    }

    /**
     * {@inheritdoc}
     */
    public function getApproved(): array
    {
        return $this->redis->sMembers($this->approvedKey) ?: [];
    }


    public function isApproved(string $name): bool
    {
        return (bool) $this->redis->sIsMember($this->approvedKey, strtolower($name));
    }

    /**
     * {@inheritdoc}
     */
    public function markApprove(string $name): void
    {
        $this->redis->sAdd($this->approvedKey, $name);
        $this->redis->sAdd($this->enabledKey, $name);
    }

    public function markDisable(string $name): void
    {
        $this->redis->sRem($this->enabledKey, $name);
    }

    /**
     * {@inheritdoc}
     */
    public function removeApprove(string $name): void
    {
        $this->redis->sRem($this->approvedKey, $name);
        $this->redis->sRem($this->enabledKey, $name);
    }
}
