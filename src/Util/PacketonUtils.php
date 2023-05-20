<?php

declare(strict_types=1);

namespace Packeton\Util;

use Composer\Package\PackageInterface;
use Symfony\Component\Finder\Glob;

class PacketonUtils
{
    public static function sort(array $packages): array
    {
        usort($packages, function (PackageInterface $a, PackageInterface $b) {
            $aVersion = $a->getVersion();
            $bVersion = $b->getVersion();
            if ($aVersion === '9999999-dev' || str_starts_with($aVersion, 'dev-')) {
                $aVersion = 'dev';
            }
            if ($bVersion === '9999999-dev' || str_starts_with($bVersion, 'dev-')) {
                $bVersion = 'dev';
            }
            $aIsDev = $aVersion === 'dev' || str_ends_with($aVersion, '-dev');
            $bIsDev = $bVersion === 'dev' || str_ends_with($bVersion, '-dev');

            // push dev versions to the end
            if ($aIsDev !== $bIsDev) {
                return $aIsDev ? 1 : -1;
            }

            // equal versions are sorted by date
            if ($aVersion === $bVersion) {
                return $a->getReleaseDate() > $b->getReleaseDate() ? 1 : -1;
            }

            // the rest is sorted by version
            return version_compare($aVersion, $bVersion);
        });

        return $packages;
    }

    public static function formatSize(int $size): string
    {
        return match (true) {
            $size > 1048576 => round($size/1048576, 1) . ' MB',
            $size > 1024 => round($size/1024, 1) . ' KB',
            default => $size . ' KB',
        };
    }

    public static function filterAllowedPaths(string $path, array $allowedPaths): ?string
    {
        try {
            $path = PacketonUtils::normalizePath($path);
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($allowedPaths as $allowed) {
            if (str_starts_with($path, $allowed)) {
                return $allowed;
            }
        }

        return null;
    }

    /**
     * @param string $path
     * @return string
     */
    public static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('#\p{C}+#u', $path)) {
            throw new \InvalidArgumentException("Invalid pathname: $path");
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            switch ($part) {
                case '':
                case '.':
                    break;
                case '..':
                    if (empty($parts)) {
                        throw new \LogicException('Path is outside of the defined root, path: [' . $path . ']');
                    }
                    array_pop($parts);
                    break;

                default:
                    $parts[] = $part;
                    break;
            }
        }

        return implode('/', $parts);
    }

    public static function buildPath(string $baseDir, ...$paths): string
    {
        $baseDir = rtrim($baseDir, '/') . '/';
        foreach ($paths as $path) {
            $baseDir .= ltrim($path, '/') . '/';
        }

        return rtrim($baseDir, '/');
    }

    public static function matchGlob(array $listOf, ?string $globs, ?string $excluded = null, ?string $included = null, string $suffix = '/composer.json'): array
    {
        $excluded = $excluded ? explode("\n", $excluded) : [];
        $globs = $globs ? explode("\n", $globs) : [];
        if (empty($globs)) {
            return [];
        }

        $globs = array_map('trim', $globs);
        $excluded = array_map('trim', $excluded);

        $listOfPackages = [];
        foreach ($globs as $glob) {
            $filterRegex = Glob::toRegex($glob);
            $listOfPackages = array_merge(
                $listOfPackages,
                array_filter($listOf, fn($name) => preg_match($filterRegex, $name) && str_ends_with($name, $suffix))
            );
        }

        $listOfPackages = array_unique($listOfPackages);
        $listOfPackages = array_map(fn($f) => trim($f, '/'), $listOfPackages);

        $listOfPackages = array_combine($listOfPackages, $listOfPackages);
        foreach ($excluded as $exclude) {
            $exclude = trim($exclude, '/');
            $exclude1 = $exclude . '/' . trim($suffix, '/');
            if (isset($listOfPackages[$exclude]) || isset($listOfPackages[$exclude1])) {
                unset($listOfPackages[$exclude], $listOfPackages[$exclude1]);
            }
        }

        $listOfPackages = array_values($listOfPackages);
        sort($listOfPackages);

        return $listOfPackages;
    }

    public static function toggleNetwork(bool $isEnabled): void
    {
        if ($isEnabled) {
            unset($_ENV['COMPOSER_DISABLE_NETWORK']);
        } else {
            $_ENV['COMPOSER_DISABLE_NETWORK'] = 1;
        }
    }
}
