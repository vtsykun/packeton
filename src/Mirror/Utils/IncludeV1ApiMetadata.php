<?php

declare(strict_types=1);

namespace Packeton\Mirror\Utils;

use Packeton\Mirror\RemoteProxyRepository;

class IncludeV1ApiMetadata
{
    public static function buildInclude(array $packages, RemoteProxyRepository $repository): array
    {
        $metadata = [];
        foreach ($packages as $package) {
            $item = $repository->findPackageMetadata($package)?->decodeJson()['packages'] ?? [];
            $metadata = $metadata + $item;
        }

        $metadata = ['packages' => $metadata];
        $content = \json_encode($metadata, \JSON_UNESCAPED_SLASHES);
        $hash = \sha1($content);
        $includes = [
            "include-packeton/all$$hash.json" => ['sha1' => $hash]
        ];

        return [$includes, $content];
    }
}
