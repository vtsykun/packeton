<?php

declare(strict_types=1);

namespace Packeton\Service;

use Packeton\Util\PacketonUtils;
use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Contracts\Cache\CacheInterface;

class AssetHashVersionStrategy implements VersionStrategyInterface
{
    public function __construct(
        protected string $publicDir,
        protected CacheInterface $cache,
        protected bool $debug = false,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getVersion(string $path): string
    {
        if (str_starts_with($path, 'packeton/') && in_array(pathinfo($path)['extension'] ?? '', ['js', 'css'], true)) {
            if (true === $this->debug) {
                return $this->getHash($path);
            }
            return $this->cache->get(sha1($path), fn() => $this->getHash($path));
        }

        return '';
    }

    protected function getHash(string $path): string
    {
        return substr(@hash_file('sha256', PacketonUtils::buildPath($this->publicDir, $path)) ?: '', 0, 6);
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
