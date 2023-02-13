<?php

declare(strict_types=1);

namespace Packeton\Composer;

use Composer\Semver\VersionParser;
use Composer\MetadataMinifier\MetadataMinifier as ComposerMetadataMinifier;

class MetadataMinifier
{
    private static $masterVersions = ['dev-master', 'dev-main', 'dev-default', 'dev-trunk'];

    /**
     * Convert metadata v1 to metadata v2
     */
    public function minify(array $metadata, bool $isDev = true, &$lastModified = null): array
    {
        $packages = $metadata['packages'] ?? [];
        $metadata['minified'] = 'composer/2.0';

        $obj = new \stdClass();
        $obj->time = '1970-01-01T00:00:00+00:00';

        foreach ($packages as $packName => $versions) {
            $versions = \array_filter($versions, fn($v) => $this->isValidStability($v, $isDev));

            \usort($versions, fn($v1, $v2) => -1 * version_compare($v1['version_normalized'], $v2['version_normalized']));
            \array_map(fn($v) => $obj->time < ($v['time'] ?? 0) ? $obj->time = ($v['time'] ?? 0) : null, $versions);

            foreach ($versions as $i => $v) {
                if (isset($v['version_normalized_v2'])) {
                    $v['version_normalized'] = $v['version_normalized_v2'];
                    unset($v['version_normalized_v2']);
                } elseif ('9999999-dev' === $v['version_normalized'] ?? null) {
                    $v['version_normalized'] = $v['version'];
                }

                $versions[$i] = $v;
            }

            $metadata['packages'][$packName] = ComposerMetadataMinifier::minify(array_values($versions));
        }

        $lastModified = $obj->time;

        return $metadata;
    }

    /**
     * Convert metadata v2 (dev + stability) to metadata v1
     */
    public function expand(array ...$metadata): array
    {
        $metadata = \array_map($this->doExpand(...), $metadata);

        $packagesBlobs = \array_column($metadata, 'packages');

        $packages = [];
        foreach ($packagesBlobs as $blobs) {
            foreach ($blobs as $packageName => $versions) {
                $packages[$packageName] = \array_merge($packages[$packageName] ?? [], $versions);
            }
        }

        foreach ($packages as $packageName => $versions) {
            $versions = \array_map(fn($v) => ['version_normalized' => self::getNormalizedVersionV1($v['version_normalized'])] + $v, $versions);
            \usort($versions, fn($v1, $v2) => \version_compare($v1['version_normalized'], $v2['version_normalized']));

            $versionMap = [];
            foreach ($versions as $version) {
                if (!isset($version['uid'])) {
                    $version['uid'] = (string) \abs(\unpack("Q", \substr(\sha1($packageName.$version['version'], true), 0, 8))[1]);
                }
                $versionMap[$version['version']] = $version;
            }

            $packages[$packageName] = $versionMap;
        }

        return ['packages' => $packages];
    }

    private function doExpand(array $metadata): array
    {
        unset($metadata['minified']);

        $packages = $metadata['packages'] ?? [];
        foreach ($packages as $packName => $versions) {
            $metadata['packages'][$packName] = ComposerMetadataMinifier::expand($versions);
        }

        return ['packages' => $metadata['packages'] ?? []];
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
