<?php

declare(strict_types=1);

namespace Packeton\Model;

use Packeton\Entity\Package;
use Packeton\Service\DistConfig;

class VirtualPackageManager
{
    public function __construct(
        private readonly DistConfig $config,
    ) {
    }

    public function buildArchive(Package $package, ?string $reference = null): ?string
    {
        $version = $package->getVersionByReference($reference) ?: $package->getVersions()->first();
        if (null === $version) {
            throw new \RuntimeException("VirtualPackage. Not found any versions for reference '$reference' of package '{$package->getName()}'");
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

        $zip = new \ZipArchive();
        $zip->open($cachedName,  \ZipArchive::CREATE);
        $zip->addFromString('composer.json', json_encode($selected, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT));
        $zip->close();

        return $cachedName;
    }

}
