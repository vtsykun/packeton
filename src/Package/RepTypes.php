<?php

declare(strict_types=1);

namespace Packeton\Package;

use Packeton\Form\Type\Package\ArtifactPackageType;
use Packeton\Form\Type\Package\CustomPackageType;
use Packeton\Form\Type\Package\IntegrationPackageType;
use Packeton\Form\Type\Package\MonoRepoPackageType;
use Packeton\Form\Type\Package\PackageType;
use Packeton\Form\Type\Package\ProxyPackageType;

class RepTypes
{
    public const VCS = 'vcs';
    public const MONO_REPO = 'mono-repo';
    public const ARTIFACT = 'artifact';
    public const INTEGRATION = 'integration';
    public const CUSTOM = 'custom';
    public const VIRTUAL = 'virtual';
    public const PROXY = 'proxy';

    private static $types = [
        self::ARTIFACT,
        self::MONO_REPO,
        self::INTEGRATION,
        self::VCS,
        self::CUSTOM,
        self::VIRTUAL,
        self::PROXY,
    ];

    public static function getFormType(?string $type): string
    {
        return match ($type) {
            self::MONO_REPO => MonoRepoPackageType::class,
            self::ARTIFACT => ArtifactPackageType::class,
            self::INTEGRATION => IntegrationPackageType::class,
            self::CUSTOM, self::VIRTUAL => CustomPackageType::class,
            self::PROXY => ProxyPackageType::class,
            default => PackageType::class,
        };
    }

    public static function isNotAutoCrawled(): array
    {
        return [self::VIRTUAL, self::CUSTOM, self::ARTIFACT];
    }

    public static function isBuildInDist(?string $type): bool
    {
        return match ($type) {
            self::ARTIFACT, self::CUSTOM, self::VIRTUAL => true,
            default => false,
        };
    }

    public static function getUITemplate(?string $type, string $action): ?string
    {
        return match ($type) {
            self::ARTIFACT => "package/{$action}Artifact.html.twig",
            self::CUSTOM, self::VIRTUAL => "package/{$action}Custom.html.twig",
            default => null,
        };
    }

    public static function normalizeType(?string $type): string
    {
        return match ($type) {
            self::MONO_REPO => self::MONO_REPO,
            self::ARTIFACT => self::ARTIFACT,
            self::INTEGRATION => self::INTEGRATION,
            self::CUSTOM => self::CUSTOM,
            self::VIRTUAL => self::VIRTUAL,
            self::PROXY => self::PROXY,
            default => self::VCS,
        };
    }
}
