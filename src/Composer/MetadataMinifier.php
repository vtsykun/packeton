<?php

declare(strict_types=1);

namespace Packeton\Composer;

use Composer\Semver\VersionParser;
use Composer\MetadataMinifier\MetadataMinifier as ComposerMetadataMinifier;

class MetadataMinifier
{
    private static $masterVersions = ['dev-master', 'dev-main', 'dev-default', 'dev-trunk'];

    public function minify(array $metadata, bool $isDev = true, &$lastModified = null): array
    {
        $packages = $metadata['packages'] ?? [];
        $metadata['minified'] = 'composer/2.0';

        $obj = new \stdClass();
        $obj->time = '1970-01-01T00:00:00+00:00';

        foreach ($packages as $packName => $versions) {
            $versions = array_filter($versions, fn($v) => $this->isValidStability($v, $isDev));

            usort($versions, fn($v1, $v2) => -1 * version_compare($v1['version_normalized'], $v2['version_normalized']));
            array_map(fn($v) => $obj->time < $v['time'] ? $obj->time = $v['time'] : null, $versions);

            foreach ($versions as $i => $v) {
                if (isset($v['version_normalized_v2'])) {
                    $v['version_normalized'] = $v['version_normalized_v2'];
                    unset($v['version_normalized_v2']);
                }

                $versions[$i] = $v;
            }

            $metadata['packages'][$packName] = ComposerMetadataMinifier::minify(array_values($versions));
        }

        $lastModified = $obj->time;

        return $metadata;
    }

    public function expand(array $metadata): array
    {
        unset($metadata['minified']);
        $packages = $metadata['packages'] ?? [];
        foreach ($packages as $packName => $versions) {
            $metadata['packages'][$packName] = ComposerMetadataMinifier::expand($versions);
        }

        return $metadata;
    }

    public static function getNormalizedVersionV1($version)
    {
        if (in_array($version, self::$masterVersions, true)) {
            return '9999999-dev';
        }

        return $version;
    }

    private function isValidStability($version, bool $isDev)
    {
        $stab = VersionParser::parseStability($version['version']);

        return $isDev ? $stab === 'dev' : $stab !== 'dev';
    }
}
