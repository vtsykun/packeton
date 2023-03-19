<?php

declare(strict_types=1);

namespace Packeton\Mirror\Utils;

use Packeton\Mirror\RemoteProxyRepository;

class ApiMetadataUtils
{
    public static function buildIncludesV1(array $packages, RemoteProxyRepository $repository): array
    {
        return static::buildIncludesLazy(
            $packages,
            fn ($p) => $repository->findPackageMetadata($p)?->decodeJson()['packages'][$p] ?? null
        );
    }

    public static function applyMetadataPatchV1(string $package, array $metadata, array $patchData): array
    {
        if (empty($patchData) || !isset($metadata['packages'][$package])) {
            return $metadata;
        }

        $data = $metadata['packages'][$package];
        foreach ($data as $i => $item) {
            if ($patch = $patchData[$item['version_normalized'] ?? ''] ?? null) {
                @[$strategy, $patch] = $patch;
                $item = match ($strategy) {
                    'merge' => \array_merge($item, $patch),
                    'merge_recursive' => \array_merge_recursive($item, $patch),
                    default => $patch,
                };

                $data[$i] = $item;
            }
        }
        $metadata['packages'][$package] = $data;

        return $metadata;
    }

    public static function buildIncludesLazy(array $packages, callable $packageProvider): array
    {
        if (empty($packages)) {
            $metadataString = \json_encode(['packages' => []]);
        } else {
            $metadataString = '{"packages": {';
            foreach ($packages as $i => $package) {
                if (!$item = $packageProvider($package)) {
                    continue;
                }
                $metadataString .= \sprintf('%s: %s', \json_encode($package, \JSON_UNESCAPED_SLASHES),  \json_encode($item, \JSON_UNESCAPED_SLASHES));
                if (\count($packages) !== $i+1) {
                    $metadataString .= ", ";
                }
            }

            \gc_collect_cycles();
            $metadataString .= '}}';
        }

        $hash = \sha1($metadataString);
        $includes = [
            "include-packeton/all$$hash.json" => ['sha1' => $hash]
        ];

        return [$includes, $metadataString];
    }
}
