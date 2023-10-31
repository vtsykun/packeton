<?php

declare(strict_types=1);

namespace Packeton\Integrations\Model;

use Psr\Cache\CacheItemInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class CustomCacheItem implements CacheItemInterface
{
    private ?int $expiry = null;

    /**
     * @internal
     */
    public function __construct(
        private readonly string $key = '',
        private mixed $value = null,
        private readonly bool $isHit = false,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->isHit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->expiry = $expiration ? (float) $expiration->getTimestamp() : null;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function expiresAfter(int|\DateInterval|null $time): static
    {
        if ($time === null) {
            $this->expiry = null;
        } elseif ($time instanceof \DateInterval) {
            $this->expiry = (new \DateTime())->add($time)->getTimestamp();
        } elseif (is_int($time)) {
            $this->expiry = $time + time();
        }

        return $this;
    }

    public function getExpiry(): ?int
    {
        return $this->expiry;
    }
}
