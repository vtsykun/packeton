<?php

declare(strict_types=1);

namespace Packeton\Util;

use Composer\Semver\VersionParser;

class ComposerDiffReview
{
    protected $versionParser;

    public function __construct()
    {
        $this->versionParser = new VersionParser();
    }

    public static function generateDiff(array $lock1, array $lock2, array $options = []): ?string
    {
        $obj = new static();
        return $obj->markdownComposerDiff($lock1, $lock2, $options);
    }

    public function markdownComposerDiff(array $lock1, array $lock2, array $options = []): ?string
    {
        [$prodPack, $devPack] = $this->getComposerLockDiff($lock1, $lock2);

        $count = count($prodPack) + count($devPack);
        $data = $this->doMarkdownFormat($prodPack) . "\n\n\n" . $this->doMarkdownFormat($devPack, 'Dev Packages');
        $data = trim($data);

        if ($data && ($options['collapse'] ?? true) && count(explode("\n", $data)) > 25) {
            $data = <<<TXT
<details>
  <summary>Click to show all ($count)</summary>

$data

</details>
TXT;
            $data = "### composer.lock changes \n\n" . $data;
        } elseif ($data) {
            $data = "### Packages changes ($count)\n\n" . $data;
        }


        return $data ?: null;
    }

    protected function doMarkdownFormat(array $data, $type = 'Prod Packages'): ?string
    {
        if (empty($data)) {
            return null;
        }

        $rows[] = [$type, 'Operation', 'Base', 'Target', 'Changes'];
        foreach ($data as $item) {
            $op = $item['op'] ?? '';
            if ('url' === ($item['flags'] ?? '')) {
                $op .= ' :sos:';
            }

            $rows[] = [$item['item']['name'] ?? '', $op, $item['base'] ?? '-', $item['target'] ?? '-', $item['change'] ?? '-'];
        }

        $lines = array_map(fn($row) => '| ' . implode(' | ', $row) . ' |', $rows);

        $header = array_shift($lines);
        $sep = '|'. str_repeat('---|---', count($rows[0])-1) . '|';
        $body = implode("\n", $lines);

        return $header . "\n" . $sep . "\n" . $body;
    }

    public function getComposerLockDiff(array $lock1, array $lock2): array
    {
        $prodPack = $this->doGetLockDiff($lock1['packages'] ?? [], $lock2['packages'] ?? []);
        $devPack = $this->doGetLockDiff($lock1['packages-dev'] ?? [], $lock2['packages-dev'] ?? []);

        return [$prodPack, $devPack];
    }

    protected function doGetLockDiff(array $packages1, array $packages2): array
    {
        $diff = [];
        $pack1s = PacketonUtils::buildChoices($packages1, 'name');
        $pack2s = PacketonUtils::buildChoices($packages2, 'name');

        foreach ($pack2s as $name => $pack2) {
            $pack1 = $pack1s[$name] ?? null;
            if (!$pack1) {
                $newUrl = $this->getPackageLink($pack2);
                $diff[] = ['op' => 'New', 'item' => $pack2, 'target' => $pack2['version'] ?? null, 'change' => $newUrl ? "[repo]($newUrl)" : null];
                continue;
            }

            try {
                if ($this->detectUrlChange($pack1, $pack2)) {
                    $newUrl = $this->getPackageLink($pack2);
                    $diff[] = ['op' => '**Dist URL Change**', 'item' => $pack2, 'flags' => 'url', 'change' => $newUrl ? "[New URL]($newUrl)" : null];
                }
                if ($this->detectUrlChange($pack1, $pack2, 'source')) {
                    $newUrl = $this->getPackageLink($pack2);
                    $diff[] = ['op' => '**Source URL Change**', 'item' => $pack2, 'flags' => 'url', 'change' => $newUrl ? "[New URL]($newUrl)" : null];
                }
            } catch (\Throwable $e) {}

            $v2 = $pack2['version'] ?? null;
            $v1 = $pack1['version'] ?? null;

            $ref2 = $pack2['source']['reference'] ?? ($pack2['dist']['reference'] ?? null);
            $ref1 = $pack1['source']['reference'] ?? ($pack1['dist']['reference'] ?? null);
            if (($v1 !== $v2 && $v2 && $v1) || ($ref1 && $ref2 && $ref2 !== $ref1)) {
                $op = match (version_compare($v2, $v1)) {
                    -1 => 'Downgraded',
                    0 => $v2 === $v1 ? 'Reference' : 'Upgraded',
                    default => 'Upgraded'
                };

                $stab1 = VersionParser::parseStability($v1);
                $stab2 = VersionParser::parseStability($v2);
                if ($stab1 === 'dev' || $stab2 === 'dev') {
                    $op = 'Upgraded';
                }

                $ref1 = substr((string)$ref1, 0, 7);
                $ref2 = substr((string)$ref2, 0, 7);
                if ($v1 === $v2) {
                    $v1 = "$v1 @$ref1";
                    $v2 = "$v2 @$ref2";
                }

                $changeLink = $this->getChangeLink($pack2, $ref1, $ref2);
                $diff[] = ['op' => $op, 'item' => $pack2, 'base' => $v1, 'target' => $v2, 'change' => $changeLink ? "[diff]($changeLink)" : null];
            }
        }

        foreach ($pack1s as $name => $value) {
            if (!isset($pack2s[$name])) {
                $diff[] = ['op' => 'Removed', 'item' => $value, 'base' => $value['version'] ?? null];
            }
        }

        return $diff;
    }

    protected function getChangeLink($pack, $ref1, $ref2): ?string
    {
        if (!$url = $this->getPackageLink($pack)) {
            return null;
        }

        $parts = parse_url($url);
        if (($parts['host'] ?? null) === 'github.com') {
            return "$url/compare/$ref1...$ref2";
        }

        return null;
    }

    protected function getPackageLink($pack): ?string
    {
        if (!$url = $pack['source']['url'] ?? ($pack['dist']['url'] ?? null)) {
            return null;
        }

        if ($parts = $this->parseUrl($url)) {
            [$domain, $namespace, $repo] = $parts;
            if (empty($domain) || empty($namespace)) {
                return null;
            }

            return "https://$domain/$namespace/$repo";
        }
        return null;
    }

    protected function parseUrl(string $url): ?array
    {
        try {
            $url = PacketonUtils::getBrowsableRepository($url) ?: $url;
            if (preg_match('#^(?:(?:https?|git)://([^/]+)/|git@([^:]+):/?)([^/]+)/([^/]+?)(?:\.git|/)?$#', $url, $match)) {
                return [strtolower($match[1] ?? (string) $match[2]), $match[3], $match[4]];
            }

            // GitLab
            if (preg_match('#^(?:(?P<scheme>https?)://(?P<domain>.+?)(?::(?P<port>[0-9]+))?/|git@(?P<domain2>[^:]+):)(?P<parts>.+)/(?P<repo>[^/]+?)(?:\.git|/)?$#', $url, $match)) {
                return [strtolower($match['domain'] ?? (string) $match['domain2']), $match['parts'], $match['repo']];
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    protected function detectUrlChange($pack1, $pack2, $type = 'dist'): bool
    {
        if (isset($pack1[$type]['url'], $pack2[$type]['url'])) {
            $url1 = (string)$pack1[$type]['url'];
            $url2 = (string)$pack2[$type]['url'];

            $url1 = str_replace([$pack1[$type]['reference'] ?? '', $pack1['version'] ?? ''], '', $url1);
            $url2 = str_replace([$pack2[$type]['reference'] ?? '', $pack2['version'] ?? ''], '', $url2);

            try {
                $url1 = preg_replace('/\.git$/', '', $url1);
                $url2 = preg_replace('/\.git$/', '', $url2);

                $v1 = $this->versionParser->normalize($pack1['version']);
                $v2 = $this->versionParser->normalize($pack2['version']);

                $url1 = str_replace($v1, '', $url1);
                $url2 = str_replace($v2, '', $url2);
            } catch (\Throwable $e){}

            try {
                $part1 = parse_url($url1);
                $part2 = parse_url($url2);

                $hasUrlChanges =  (($part1['host'] ?? null) !== ($part2['host'] ?? null)) ||
                    trim($part1['path'] ?? '', '/') !== trim($part2['path'] ?? '', '/');

                if ($hasUrlChanges && ($part1 = $this->parseUrl($url1)) && ($part2 = $this->parseUrl($url2))) {
                    $hasUrlChanges = $part1 !== $part2;
                }

                return $hasUrlChanges;
            } catch (\Throwable $e) {
                return $url1 !== $url2;
            }
        }

        return false;
    }
}
