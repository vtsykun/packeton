<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

/**
 * JSON dump metadata options.
 * See Configuration.
 */
class MetadataOptions
{
    public function __construct(protected array $config)
    {
    }

    public function isLazy(): bool
    {
        return ($this->config['sync_lazy'] ?? false);
    }

    public function parentNotify(): bool
    {
        return $this->config['parent_notify'] ?? true;
    }

    public function info(): ?string
    {
        return $this->config['info_cmd_message'] ?? null;
    }

    public function disableV1Format(): bool
    {
        return $this->config['disable_v1'] ?? false;
    }

    public function isDistMirror(): bool
    {
        return $this->config['enable_dist_mirror'] ?? true;
    }

    public function getAlias(): string
    {
        return $this->config['name'];
    }

    public function getAvailablePatterns(): array
    {
        return $this->config['available_package_patterns'] ?? [];
    }

    public function getAvailablePackages(): array
    {
        return $this->config['available_packages'] ?? [];
    }

    public function maxCountOfAvailablePackages(): int
    {
        return $this->config['available_packages_count_limit'] ?? 5000;
    }

    public function withRoot(array $root): static
    {
        $clone = clone $this;
        $clone->config = \array_merge($this->config, ['root' => $root]);

        return $clone;
    }
}
