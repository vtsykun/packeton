<?php

declare(strict_types=1);

namespace Packeton\Service;

use Packeton\Util\PacketonUtils;
use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Contracts\Cache\CacheInterface;

class AssetHashVersionStrategy implements VersionStrategyInterface
{
    public function __construct(protected string $publicDir, protected CacheInterface $cache)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getVersion(string $path): string
    {
        if (str_starts_with($path, 'packeton/') && in_array(pathinfo($path)['extension'] ?? '', ['js', 'css'], true)) {
            return $this->cache->get(sha1($path), fn() => substr(@hash_file('sha256', PacketonUtils::buildPath($this->publicDir, $path)) ?: '', 0, 6));
        }

        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function applyVersion(string $path): string
    {
        if ($version = $this->getVersion($path)) {
            $path .= '?v=' . $version;
        }

        return $path;
    }
}
