<?php

declare(strict_types=1);

namespace Packeton\Composer;

enum MetadataFormat: string
{
    case AUTO = 'auto';
    case ONLY_V1 = 'only_v1';
    case ONLY_V2 = 'only_v2';
    case FULL = 'full';

    public function providerIncludes(int $version = null): bool
    {
        return match(true) {
            $this === MetadataFormat::AUTO && $version === 1, $this === MetadataFormat::ONLY_V1, $this === MetadataFormat::FULL => true,
            default => false
        };
    }

    public function lazyProviders(int $version = null): bool
    {
        return match(true) {
            $this === MetadataFormat::AUTO && $version !== 1 => true,
            default => false
        };
    }

    public function metadataUrl(int $version = null): bool
    {
        return match(true) {
            $this === MetadataFormat::ONLY_V1 => false,
            default => true
        };
    }
}
