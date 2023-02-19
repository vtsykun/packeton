<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

class ProxyOptions extends MetadataOptions
{
    /**
     * @param string|null $path
     *
     * @return string
     */
    public function getUrl(string $path = null): string
    {
        $url = \rtrim($this->config['url'], '/');

        // Full url
        if ($path && \preg_match('#^https?://#', $path)) {
            return $path;
        }

        return $path ? $url . '/' . \ltrim($path, '/') : $url;
    }

    public function getMetadataV2Url(string $package = null): ?string
    {
        if (!isset($this->config['root']['metadata-url'])) {
            return null;
        }

        $url = $this->getUrl($this->config['root']['metadata-url']);
        return \str_replace('%package%', $package ?? '%package%', $url);
    }

    public function hasV2Api(): bool
    {
        return (bool) ($this->config['root']['metadata-url'] ?? false);
    }

    public function getV2SyncApi(): ?string
    {
        if (!\is_string($this->config['root']['metadata-changes-url'] ?? null)) {
            return null;
        }

        return $this->getUrl($this->config['root']['metadata-changes-url']);
    }

    public function getMetadataV1Url(string $package = null, string $hash = null): ?string
    {
        $providersUrl = $this->config['root']['providers-url'] ??
            ($this->config['root']['providers-lazy-url'] ?? null);

        if (null === $providersUrl) {
            return null;
        }

        $url = $this->getUrl($providersUrl);
        return \str_replace(['%package%', '%hash%'], [$package ?? '%package%', $hash ?? '%hash%'], $url);
    }

    public function getRoot(): array
    {
        return $this->config['root'] ?? [];
    }

    public function getRootProviders(): array
    {
        return $this->config['root']['providers'] ?? [];
    }

    public function getIncludes(): array
    {
        return $this->config['root']['includes'] ?? [];
    }

    public function getProviderIncludes($withHash = false): array
    {
        $providerIncludes = $this->config['root']['provider-includes'] ?? [];
        if ($withHash === true) {
            foreach ($providerIncludes as $name => $hash) {
                $uri = \str_replace('%hash%', $hash['sha256'] ?? '', $name);
                $providerIncludes[$name] = $uri;
            }
        }

        return $providerIncludes;
    }

    public function getSyncInterval(): ?int
    {
        return $this->config['sync_interval'] ?? null;
    }

    public function capabilities(): array
    {
        $flags = [
            isset($this->config['root']['metadata-url']) ? 'API_V2' : null,
            isset($this->config['root']['providers-url']) || isset($this->config['root']['providers-lazy-url']) ? 'API_V1' : null,
            isset($this->config['root']['metadata-changes-url']) ? 'API_META_CHANGE' : null,
            !isset($this->config['root']['providers-url']) && isset($this->config['root']['providers-lazy-url']) ? 'API_V1_LAZY' : null,
            ($this->config['root']['packages'] ?? []) ? 'API_V1_PACKAGES' : null,
            ($this->config['root']['includes'] ?? []) ? 'API_V1_INCLUDES' : null,
        ];

        return \array_values(\array_filter($flags));
    }

    public function reference(): int
    {
        return (\unpack('L', \substr(sha1($this->getAlias(), true), 0, 4))[1] ?? 0) % 1073741824;
    }

    public function isPackagist()
    {
        $hostname = \parse_url($this->getUrl(), \PHP_URL_HOST);
        return \in_array($hostname, ['repo.packagist.org', 'packagist.org']);
    }

    public function logo(): ?string
    {
        return $this->config['logo'] ?? null;
    }

    /**
     * Get HTTP configuration
     *
     * @return array
     */
    public function http(): array
    {
        return $this->config['options']['http'] ?? [];
    }

    /**
     * @return array|null
     */
    public function getAuthBasic(): ?array
    {
        return $this->config['http_basic'] ?? null;
    }

    /**
     * @return array|null
     */
    public function getComposerAuth(): ?array
    {
        return $this->config['composer_auth'] ?? null;
    }

    public function getStats(string $name = null, mixed $default = null): mixed
    {
        $stats = $this->config['stats'] ?? [];
        return $name ? $stats[$name] ?? $default : $stats;
    }
}
