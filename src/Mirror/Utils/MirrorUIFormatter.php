<?php

declare(strict_types=1);

namespace Packeton\Mirror\Utils;

class MirrorUIFormatter
{
    public static function groupPackageByVendor(array $packages, $maxGroup = 7): array
    {
        $byVendors = [];
        foreach ($packages as $package) {
            [$vendor] = \explode('/', $package['name']);
            $byVendors[$vendor][] = $package;
        }

        $grouped = $otherVendors = [];
        \uasort($byVendors, fn($a, $b) => -1 * (\count($a) <=> \count($b)));
        foreach ($byVendors as $vendorName => $items) {
            if (\count($grouped) < $maxGroup && \count($items) > 3) {
                $grouped[$vendorName] = $items;
            } else {
                $otherVendors = \array_merge($otherVendors, $items);
            }
        }

        if ($otherVendors) {
            $grouped['other'] = $otherVendors;
        }
        foreach ($grouped as $vendor => $items) {
            \usort($items, fn($a, $b) => $a['name'] <=> $b['name']);

            $grouped[$vendor] = [
                'items' => $items,
                'count' => \count($items),
                'new' => \count(\array_filter($items, fn($p) => !$p['approved'])),
                'private' => \count(\array_filter($items, fn($p) => $p['private'])),
            ];
        }

        return $grouped;
    }

    public static function getGridPackagesData(array $approved, array $enabled, array $privatePackages, array $patched = []): array
    {
        $packages = [];
        $approved = \array_flip($approved);
        $patched = \array_flip($patched);
        $privatePackages = \array_flip($privatePackages);

        foreach ($enabled as $name) {
            $packages[$name] = [
                'name' => $name,
                'enabled' => true,
                'approved' => isset($approved[$name]),
                'private' => isset($privatePackages[$name]),
                'patched' => isset($patched[$name]),
            ];
        }

        return self::groupPackageByVendor($packages);
    }
}
