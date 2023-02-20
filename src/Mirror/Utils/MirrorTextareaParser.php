<?php

declare(strict_types=1);

namespace Packeton\Mirror\Utils;

class MirrorTextareaParser
{
    public function __construct(private readonly string $packageRegexp)
    {
    }

    public function parser(?string $string): array
    {
        if (empty($string)) {
            return [];
        }

        return \array_map('strtolower', $this->doParser($string));
    }

    private function doParser(string $string): array
    {
        if (empty($string)) {
            return [];
        }

        $data = \json_decode($string, true);

        if (\is_array($data['require'] ?? null)) {
            $packages = \array_keys(\array_merge($data['require'] ?? [], $data['require-dev'] ?? []));
            return \array_values(\array_filter($packages, fn ($p) => str_contains($p, '/')));
        }

        if (\is_array($data['packages'] ?? null)) {
            $packages = \array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []);
            return \array_column($packages, 'name');
        }

        if (is_array($data)) {
            return [];
        }

        // Invalid composer json.
        \preg_match_all('#"(' . $this->packageRegexp . ')":#', $string, $packages);
        if ($packages[1] ?? null) {
            return $packages[1];
        }

        // Use composer info output
        if (\preg_match('#(\d+)\.(\d+)\.(\d+)#', $string)) {
            $list = \explode(PHP_EOL, $string);
            $packages = \array_filter(
                \array_map(fn ($s) => \preg_split('/[\s,]+/', $s)[0] ?? null, $list),
                fn($p) => $p && \preg_match('#^'. $this->packageRegexp. '$#', $p)
            );
        } else if (\count(\explode('/', $string)) === 2) {
            $packages = [\trim($string)];
        } else {
            $packages = \preg_split('/[\s,]+/', $string);
            $packages = \array_filter($packages, fn($p) => \preg_match('#^'. $this->packageRegexp. '$#', $p));
        }

        return \array_values($packages);
    }
}
