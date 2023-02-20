<?php

declare(strict_types=1);

namespace Packeton\Mirror\Utils;

use Packeton\Mirror\RemoteProxyRepository;

class IncludeV1ApiMetadata
{
    public static function buildInclude(array $packages, RemoteProxyRepository $repository): array
    {
        if (empty($packages)) {
            $metadataString = \json_encode(['packages' => []]);
        } else {
            $metadataString = '{"packages": {';
            foreach ($packages as $i => $package) {
                $item = $repository->findPackageMetadata($package)?->decodeJson()['packages'][$package] ?? [];
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
