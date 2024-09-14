<?php

declare(strict_types=1);

namespace Packeton\Model;

use League\Flysystem\FilesystemOperator;
use Packeton\Composer\Repository\PacketonRepositoryInterface;
use Packeton\Entity\Package;
use Packeton\Service\DistConfig;

class ComposerProxyPackageManager
{
    public function __construct(
        private readonly DistConfig $config,
        private readonly FilesystemOperator $baseStorage,
    ) {
    }

    public function buildArchive(Package $package, PacketonRepositoryInterface $repository, ?string $reference = null): ?string
    {
        $version = $package->getVersionByReference($reference) ?: $package->getVersions()->first();
        if (null === $version) {
            throw new \RuntimeException("Not found any versions for reference '$reference' of package '{$package->getName()}'");
        }

        $keyName = $this->config->buildName($package->getName(), $version->getReference(), $version->getVersion());
        $cachedName = $this->config->resolvePath($keyName);
        if (file_exists($cachedName)) {
            return $cachedName;
        }

        $selected = [];
        $serialized = $package->getCustomVersions();
        foreach ($serialized as $data) {
            $verName = $data['version'] ?? null;
            if ($verName === $version->getVersion() || $verName === $version->getNormalizedVersion()) {
                $selected = $data['definition'] ?? [];
                $selected['version'] = $version->getVersion();
            }
        }

        $selected['name'] = $package->getName();
        $dir = dirname($cachedName);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $url = $package->getVersionByReference($reference)->getDist()['proxy_url'];
        $response = $repository->getHttpDownloader()->get($url);
        $body = (string) $response->getBody();

        $this->baseStorage->write($keyName, $body);

        return $cachedName;
    }

}
