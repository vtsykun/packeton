<?php

declare(strict_types=1);

namespace Packeton\Util;

use Composer\Package\PackageInterface;
use Packeton\Entity\Package;
use Packeton\Repository\PackageRepository;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\Finder\Glob;

class PacketonUtils
{

    /**
     * @param array $packages
     * @return PackageInterface[]
     */
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

    public static function findPackagesByPayload(array $payload, PackageRepository $repo, bool $multiply = false): Package|array|null
    {
        $urlRegex = $url = null;
        if (isset($payload['project']['git_http_url'])) { // gitlab event payload
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = $payload['project']['git_http_url'];
        } elseif (isset($payload['repository']['html_url']) && !isset($payload['repository']['url'])) { // gitea event payload https://docs.gitea.io/en-us/webhooks/
            $urlRegex = '{^(?:ssh://(git@|gitea@)|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = $payload['repository']['html_url'];
        } elseif (isset($payload['repository']['url'])) { // github/anything hook
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = $payload['repository']['url'];
            $url = \str_replace('https://api.github.com/repos', 'https://github.com', $url);
        } elseif (isset($payload['repository']['links']['html']['href'])) { // bitbucket push event payload
            $urlRegex = '{^(?:https?://|git://|git@)?(?:api\.)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(\.git)?/?$}i';
            $url = $payload['repository']['links']['html']['href'];
        } elseif (isset($payload['repository']['links']['clone'][0]['href'])) { // bitbucket on-premise
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = '';
            foreach ($payload['repository']['links']['clone'] as $id => $data) {
                if ($data['name'] == 'ssh') {
                    $url = $data['href'];
                    break;
                }
            }
        } elseif (isset($payload['canon_url']) && isset($payload['repository']['absolute_url'])) { // bitbucket post hook (deprecated)
            $urlRegex = '{^(?:https?://|git://|git@)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(\.git)?/?$}i';
            $url = $payload['canon_url'] . $payload['repository']['absolute_url'];
        }

        if (empty($url) || empty($urlRegex)) {
            return null;
        }

        // Use the custom regex
        if (isset($payload['packeton']['regex'])) {
            $urlRegex = $payload['packeton']['regex'];
        }

        if (!preg_match($urlRegex, $url, $matched)) {
            return null;
        }

        $packages = [];
        foreach ($repo->getWebhookDataForUpdate() as $package) {
            if ($package['repository']
                && preg_match($urlRegex, $package['repository'], $candidate)
                && strtolower($candidate['host']) === strtolower($matched['host'])
                && strtolower($candidate['path']) === strtolower($matched['path'])
                && ($found = $repo->find($package['id']))
            ) {
                if ($multiply === false) {
                    return $found;
                }

                $packages[] = $found;
            }
        }

        return $packages ? : null;
    }

    public static function getBrowsableRepository(string $repository): ?string
    {
        if (preg_match('{(://|@)bitbucket.org[:/]}i', $repository)) {
            return preg_replace('{^(?:git@|https://|git://)bitbucket.org[:/](.+?)(?:\.git)?$}i', 'https://bitbucket.org/$1', $repository);
        }

        if (preg_match('{^(git://github.com/|git@github.com:)}', $repository)) {
            return preg_replace('{^(git://github.com/|git@github.com:)}', 'https://github.com/', $repository);
        }

        if (preg_match('{^((git|ssh)@(.+))}', $repository, $match) && isset($match[3])) {
            return 'https://' . str_replace(':', '/', $match[3]);
        }

        return $repository;
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

        return (str_starts_with($path, '/') ? '/' : '') . implode('/', $parts);
    }

    public static function buildPath(string $baseDir, ...$paths): string
    {
        $baseDir = rtrim($baseDir, '/') . '/';
        foreach ($paths as $path) {
            $baseDir .= ltrim($path, '/') . '/';
        }

        return rtrim($baseDir, '/');
    }

    public static function buildChoices(array $listOf, string $key, string $value = null): array
    {
        return array_combine(array_column($listOf, $key), $value ? array_column($listOf, $value) : $listOf);
    }

    public static function matchGlobAll(array $listOf, null|string|array $globs, string|array $excluded = null): array
    {
        $excluded = is_string($excluded) ? explode("\n", $excluded) : ($excluded ?: []);
        $globs = is_string($globs) ? explode("\n", $globs) : ($globs ?: []);

        $globs = array_map('trim', $globs);
        $excluded = array_map('trim', $excluded);

        $listOfPackages = [];
        if ($globs) {
            foreach ($globs as $glob) {
                $filterRegex = Glob::toRegex($glob);
                $listOfPackages = array_merge(
                    $listOfPackages,
                    array_filter($listOf, fn($name) => preg_match($filterRegex, $name))
                );
            }
        } else {
            $listOfPackages = $listOf;
        }

        $listOfPackages = array_unique($listOfPackages);
        $listOfPackages = array_map(fn($f) => trim($f, '/'), $listOfPackages);

        $listOfPackages = array_combine($listOfPackages, $listOfPackages);
        foreach ($excluded as $exclude) {
            $exclude = trim($exclude, '/');
            if (isset($listOfPackages[$exclude])) {
                unset($listOfPackages[$exclude]);
            }
        }

        $listOfPackages = array_values($listOfPackages);
        sort($listOfPackages);

        return $listOfPackages;
    }

    public static function matchGlob(array $listOf, null|string|array $globs, string|array $excluded = null, ?string $suffix = '/composer.json'): array
    {
        $excluded = is_string($excluded) ? explode("\n", $excluded) : ($excluded ?: []);
        $globs = is_string($globs) ? explode("\n", $globs) : ($globs ?: []);
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
                array_filter($listOf, fn($name) => preg_match($filterRegex, $name) && (null === $suffix || str_ends_with($name, $suffix)))
            );
        }

        $listOfPackages = array_unique($listOfPackages);
        $listOfPackages = array_map(fn($f) => trim($f, '/'), $listOfPackages);

        $listOfPackages = array_combine($listOfPackages, $listOfPackages);
        foreach ($excluded as $exclude) {
            $exclude = trim($exclude, '/');
            $exclude1 = $exclude . '/' . ($suffix ? trim($suffix, '/') : '');
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

    public static function readStream($stream): callable
    {
        return static function () use ($stream) {
            /** @var resource $out */
            $out = fopen('php://output', 'wb');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);
        };
    }

    public static function setCompilerExtensionPriority(string|ExtensionInterface $extension, ContainerBuilder $container, int $order)
    {
        \Closure::bind(static function() use ($extension, $container, $order) {
            $aliasName = is_string($extension) ? $extension : $extension->getAlias();
            $target = $container->extensions[$aliasName] ?? null;
            $i = 0;
            $result = [];
            foreach ($container->extensions as $alias => $extension) {
                if ($i >= $order && null !== $target) {
                    $result[$aliasName] = $target;
                    $target = null;
                }

                if ($alias !== $aliasName) {
                    $result[$alias] = $extension;
                    $i++;
                }
            }
            $container->extensions = $result;
        }, null, ContainerBuilder::class)();
    }

    public static function parserRepositoryUrl(string $url): array
    {
        $namespace = $httpUrl = $sshUrl = $hostname = null;

        if (preg_match('#^https?://bitbucket\.org/([^/]+)/([^/]+?)(?:\.git|/?)?$#i', $url, $match, PREG_UNMATCHED_AS_NULL)) {
            $namespace = $match[1] . $match[2];
            $hostname = 'bitbucket.org';

            $sshUrl = 'git@' . $hostname . ':'.$namespace.'.git';
            $httpUrl = 'https://' . $hostname . '/'.$namespace.'.git';
        } else if (str_contains($url, 'github.com')
            && preg_match('#^(?:(?:https?|git)://([^/]+)/|git@([^:]+):/?)([^/]+)/([^/]+?)(?:\.git|/)?$#', $url, $match, PREG_UNMATCHED_AS_NULL)
            && is_string($match[3] ?? null)
            && is_string($match[4] ?? null)
        ) { // GitHub
            $namespace = $match[3] . '/' . $match[4];
            $hostname =  strtolower($match[1] ?? (string) $match[2]);
            if ($hostname === 'www.github.com') {
                $hostname = 'github.com';
            }

            $sshUrl = 'git@' . $hostname . ':'.$namespace.'.git';
            if (str_contains($hostname, ':')) {
                $sshUrl = 'ssh://git@' . $hostname . '/'.$namespace.'.git';
            }
            $httpUrl = 'https://' . $hostname . '/'.$namespace.'.git';
        } else if (preg_match('#^(?:(?P<scheme>https?)://(?P<domain>.+?)(?::(?P<port>[0-9]+))?/|git@(?P<domain2>[^:]+):)(?P<parts>.+)/(?P<repo>[^/]+?)(?:\.git|/)?$#', $url, $match, PREG_UNMATCHED_AS_NULL)
            && is_string($match['parts'] ?? null)
            && is_string($match['repo'] ?? null)
        ) { // GitLab

            $origin = $hostname = $match['domain'] ?? (string) $match['domain2'];
            if ($match['port'] ?? null) {
                $origin .= ':'.$match['port'];
            }

            $namespace = $match['parts'] . '/' . preg_replace('#(\.git)$#', '', $match['repo']);

            $httpUrl = ($match['scheme'] ?? 'https') . '://' . $origin . '/'. $namespace .'.git';
            $sshUrl = 'git@' . $hostname . ':'.$namespace.'.git';
            if (str_contains($origin, ':')) {
                $sshUrl = 'ssh://git@' . $origin . '/'.$namespace.'.git';
            }
        }

        return [
            'namespace' => $namespace,
            'http_url' => $httpUrl,
            'ssh_url' => $sshUrl,
            'origin_url' => $url,
            'hostname' => $hostname,
        ];
    }
}
