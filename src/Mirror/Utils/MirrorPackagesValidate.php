<?php

declare(strict_types=1);

namespace Packeton\Mirror\Utils;

use Packeton\Mirror\RemoteProxyRepository;
use Packeton\Mirror\Service\FetchPackageMetadataService;

class MirrorPackagesValidate
{
    public function __construct(
        private readonly FetchPackageMetadataService $fetchMetadataService
    ) {
    }

    public function checkPackages(RemoteProxyRepository $repo, array $packages): array
    {
        $waiting = $valid = [];
        foreach ($packages as $package) {
            if ($meta = $this->getData($repo->findPackageMetadata($package)?->decodeJson(), $package)) {
                $valid[$package] = $meta;
            } else {
                $waiting[] = $package;
            }
        }

        if ($waiting) {
            $resolved = $this->fetchMetadataService->fetchPackageMetadata($waiting, $repo);
            foreach ($resolved as $package => $meta) {
                if ($meta = $this->getData($meta, $package)) {
                    $valid[$package] = $meta;
                }
            }
        }

        return [
            'validData' => \array_values($valid),
            'invalid' => \array_values(\array_diff($packages, \array_keys($valid))),
            'valid' => \array_keys($valid),
        ];
    }

    private function getData(?array $meta, string $package): ?array
    {
        if (!$data = ($meta['packages'][$package] ?? null)) {
            return null;
        }

        $item = $data['dev-master'] ?? end($data);

        return [
            'name' => $package,
            'license' => \json_encode($item['license'] ?? null),
            'description' => $item['description'],
        ];
    }
}
