<?php

declare(strict_types=1);

namespace Packeton\Mirror;

use Packeton\Composer\Util\ConfigFactory;
use Packeton\Mirror\Model\GZipTrait;
use Packeton\Mirror\Model\JsonMetadata;
use Packeton\Mirror\Service\Filesystem;
use Packeton\Mirror\Service\RemotePackagesManager;

/**
 * Filesystem mirror proxy repo for metadata.
 */
class RemoteProxyRepository extends AbstractProxyRepository
{
    use GZipTrait;

    protected const ROOT_PACKAGE = 'packages.json';

    protected string $rootFilename;
    protected string $providersDir;
    protected string $packageDir;

    public function __construct(
        protected array $repoConfig,
        protected ?string $mirrorRepoMetaDir,
        protected Filesystem $filesystem,
        protected \Redis $redis,
        protected RemotePackagesManager $rpm
    ) {
        if (null === $mirrorRepoMetaDir) {
            $mirrorRepoMetaDir = ConfigFactory::getHomeDir();
        }

        $this->mirrorRepoMetaDir = \rtrim($mirrorRepoMetaDir, '/') . '/' . $this->repoConfig['name'];
        $this->rootFilename = $this->mirrorRepoMetaDir . '/' . self::ROOT_PACKAGE;
        $this->providersDir = $this->mirrorRepoMetaDir . '/p/';
        $this->packageDir = $this->mirrorRepoMetaDir . '/package/';
    }

    /**
     * @param string|null $path
     * @return string
     */
    public function getUrl(string $path = null): string
    {
        return $this->getConfig()->getUrl($path);
    }

    /**
     * {@inheritdoc}
     */
    public function rootMetadata(): ?JsonMetadata
    {
        if ($this->filesystem->exists($this->rootFilename)) {
            return $this->createMetadataFromFile($this->rootFilename);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function findProviderMetadata(string $providerName): ?JsonMetadata
    {
        $filename = $this->providersDir . $this->providerShort($providerName);

        if ($this->filesystem->exists($filename)) {
            return $this->createMetadataFromFile($filename);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function findPackageMetadata(string $name): ?JsonMetadata
    {
        @[$package, $hash] = \explode('$', $name);

        $packageName  = \explode('/', $package)[1];
        $filename = $this->packageDir . $this->packageShort($package, $hash);

        if (null !== $hash && $this->filesystem->exists($filename)) {
            return $this->createMetadataFromFile($filename, $hash);
        }

        $dir = \rtrim(\dirname($filename), '/') . '/';
        $last = $this->filesystem->globLast($dir . $packageName . '*');
        return $last ? $this->createMetadataFromFile($last) : null;
    }

    protected function createMetadataFromFile(string $filename, string $hash = null): JsonMetadata
    {
        $content = \file_get_contents($filename);
        $unix = \filemtime($filename) ?: null;

        return new JsonMetadata($content, $unix, $hash, $this->repoConfig);
    }

    public function dumpRootMeta(array $root): void
    {
        $rootJson = \json_encode($root, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->filesystem->dumpFile($this->rootFilename, $rootJson);
        $this->proxyOptions = null;
    }

    public function isRootFresh(array $root): bool
    {
        if ($this->filesystem->exists($this->rootFilename)) {
            $rootJson = \json_encode($root, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return \sha1_file($this->rootFilename) === sha1($rootJson);
        }
        return false;
    }

    public function hasPackage(string $package, string $hash = null): bool
    {
        $filename = $this->packageDir . $this->packageShort($package, $hash);
        return $this->filesystem->exists($filename);
    }

    public function dumpPackage(string $package, string|array|null $content, ?string $hash = null): void
    {
        $content = \is_array($content) ? \json_encode($content, \JSON_UNESCAPED_SLASHES) : $content;
        $content = $content ? $this->encode($content) : $content;
        if (empty($content)) {
            return;
        }

        $filename = $this->packageDir . $this->packageShort($package, $hash);
        $this->filesystem->dumpFile($filename, $content);
    }

    public function hasProvider(string $uri): bool
    {
        $filename = $this->providersDir . $this->providerShort($uri);

        return $this->filesystem->exists($filename);
    }

    public function dumpProvider(string $uri, string|array $content): void
    {
        $content = \is_array($content) ? \json_encode($content, JSON_UNESCAPED_SLASHES) : $content;

        $content = $this->encode($content);
        $filename = $this->providersDir . $this->providerShort($uri);
        $this->filesystem->dumpFile($filename, $content);
    }

    public function lookupAllProviders(): iterable
    {
        $config = $this->getConfig();
        if ($config->getRootProviders()) {
            yield $config->getRootProviders();
        }

        foreach ($config->getProviderIncludes(true) as $provider) {
            $filename = $this->providersDir . $this->providerShort($provider);
            if ($this->filesystem->exists($filename)) {
                $content = \file_get_contents($filename);
                $content = $content ? $this->encode($content) : null;
                $content = $content ? \json_decode($content, true) : [];
                yield $content;
            }
        }
    }

    public function getRootDir(): string
    {
        return $this->mirrorRepoMetaDir;
    }

    public function getStats(): ?array
    {
        $stats = $this->redis->get("proxy-info-{$this->repoConfig['name']}");
        $stats = $stats ? json_decode($stats, true) : [];

        return is_array($stats) ? $stats : [];
    }

    public function setStats(array $stats = []): void
    {
        $this->redis->set("proxy-info-{$this->repoConfig['name']}", json_encode($stats));
    }

    public function getPackageManager(): RemotePackagesManager
    {
        return $this->rpm;
    }

    protected function packageShort(string $package, string $hash = null): string
    {
        return $package . ($hash ? '__' . $hash : '') . '.json.gz';
    }

    protected function providerShort(string $uri): string
    {
        preg_match('/([a-z0-9-_]+)\$/', $uri, $match);

        return ($match[1] ?? 'provider') . '__' . sha1($uri) . '.json.gz';
    }
}
