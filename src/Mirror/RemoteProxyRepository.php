<?php

declare(strict_types=1);

namespace Packeton\Mirror;

use Packeton\Composer\Util\ConfigFactory;
use Packeton\Mirror\Model\GZipTrait;
use Packeton\Mirror\Model\JsonMetadata;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\Model\RepoCaps;
use Packeton\Mirror\Service\RemotePackagesManager;
use Packeton\Mirror\Service\ZipballDownloadManager;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Filesystem mirror proxy repo for metadata.
 */
class RemoteProxyRepository extends AbstractProxyRepository
{
    use GZipTrait;

    protected const ROOT_PACKAGE = 'packages.json';
    protected const HASH_SEPARATOR = '____';

    protected string $rootFilename;
    protected string $providersDir;
    protected string $packageDir;

    protected string $redisRootKey;
    protected string $redisStatKey;

    protected string $ds;

    public function __construct(
        protected array $repoConfig,
        protected ?string $mirrorRepoMetaDir,
        protected Filesystem $filesystem,
        protected \Redis $redis,
        protected RemotePackagesManager $rpm,
        protected ZipballDownloadManager $zipballManager
    ) {
        if (null === $mirrorRepoMetaDir) {
            $mirrorRepoMetaDir = ConfigFactory::getHomeDir();
        }

        $this->ds = DIRECTORY_SEPARATOR;

        $this->mirrorRepoMetaDir = \rtrim($mirrorRepoMetaDir, $this->ds) . $this->ds . $this->repoConfig['name'];
        $this->rootFilename = $this->mirrorRepoMetaDir . $this->ds . self::ROOT_PACKAGE;
        $this->providersDir = $this->mirrorRepoMetaDir . $this->ds . 'p' . $this->ds;
        $this->packageDir = $this->mirrorRepoMetaDir . $this->ds . 'package' . $this->ds;

        $this->redisRootKey = "proxy-root-{$this->repoConfig['name']}";
        $this->redisStatKey = "proxy-info-{$this->repoConfig['name']}";

        $this->zipballManager->setRepository($this);
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
    public function rootMetadata(int $modifiedSince = null): ?JsonMetadata
    {
        if ($this->filesystem->exists($this->rootFilename)) {
            return $this->createMetadataFromFile($this->rootFilename);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function findProviderMetadata(string $providerName, int $modifiedSince = null): ?JsonMetadata
    {
        $filename = $this->providersDir . $this->providerKey($providerName);

        if ($this->filesystem->exists($filename)) {
            return $this->createMetadataFromFile($filename);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function findPackageMetadata(string $name, int $modifiedSince = null): ?JsonMetadata
    {
        @[$package, $hash] = \explode('$', $name);

        $config = $this->getConfig();
        // Satis API. Check includes without loading big data
        if ($config->matchCaps([RepoCaps::INCLUDES, RepoCaps::PACKAGES])) {
            if ($modifiedSince && $config->lastModifiedUnix() <= $modifiedSince) {
                return JsonMetadata::createNotModified($config->lastModifiedUnix());
            }

            $root = $this->rootMetadata()?->decodeJson() ?: [];
            if ($packages = $this->lookIncludePackageMetadata($root, $package)) {
                $content = \json_encode(['packages' => [$package => $packages]], \JSON_UNESCAPED_SLASHES);
                $unix = $config->lastModifiedUnix();
                return new JsonMetadata($content, $unix, null, $this->repoConfig);
            }
        }

        $filename = $this->packageDir . $this->packageKey($package, $hash);
        if ($this->filesystem->exists($filename)) {
            return $this->createMetadataFromFile($filename, $hash, $modifiedSince);
        }

        return null;
    }

    // See loadIncludes in the ComposerRepository
    protected function lookIncludePackageMetadata(array $data, string $package): array
    {
        $packages = [];
        // legacy repo handling
        if (!isset($data['packages']) && !isset($data['includes'])) {
            foreach ($data as $pkg) {
                if (isset($pkg['versions']) && \is_array($pkg['versions'])) {
                    foreach ($pkg['versions'] as $metadata) {
                        if (($metadata['name'] ?? null) === $package) {
                            $packages[] = $metadata;
                        }
                    }
                }
            }

            return $packages;
        }

        if (isset($data['packages'][$package])) {
            return $data['packages'][$package];
        }

        if (isset($data['includes']) && \is_array($data['includes'])) {
            foreach ($data['includes'] as $include => $metadata) {
                $includedData = $this->findProviderMetadata($include)?->decodeJson();
                if ($includedData && ($packages = $this->lookIncludePackageMetadata($includedData, $package))) {
                    return $packages;
                }
            }
        }

        return $packages;
    }

    protected function createMetadataFromFile(string $filename, string $hash = null, int $modifiedSince = null): JsonMetadata
    {
        $unix = @\filemtime($filename) ?: null;
        if ($modifiedSince && $unix && $unix <= $modifiedSince) {
            return JsonMetadata::createNotModified($modifiedSince);
        }

        $content = \file_get_contents($filename);
        return new JsonMetadata($content, $unix, $hash, $this->repoConfig);
    }

    public function dumpRootMeta(array $root): void
    {
        $rootJson = \json_encode($root, \JSON_UNESCAPED_SLASHES);
        $this->filesystem->dumpFile($this->rootFilename, $rootJson);
        $this->updateRootStats($root);

        $this->proxyOptions = null;
    }

    protected function updateRootStats(array $root = null): array
    {
        $root ??= $this->rootMetadata()?->decodeJson() ?: [];
        if ($root) {
            $modifiedSince = @\filemtime($this->rootFilename) ?: \time();
            $root['__packages'] = (bool)($root['packages'] ?? ($root['__packages'] ?? false));
            $root['modified_since'] = $modifiedSince;
            unset($root['packages']);

            $this->redis->set($this->redisRootKey, \json_encode($root));
            return $root;
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    protected function getRootMetadataInfo(): array
    {
        $root = $this->redis->get($this->redisRootKey);
        $root = $root ? \json_decode($root, true) : [];

        if (empty($root) && ($root = parent::getRootMetadataInfo())) {
            $root = $this->updateRootStats($root);
        }

        return $root;
    }

    public function isRootFresh(array $root): bool
    {
        if ($this->filesystem->exists($this->rootFilename)) {
            $rootJson = \json_encode($root, \JSON_UNESCAPED_SLASHES);
            return \sha1_file($this->rootFilename) === \sha1($rootJson);
        }
        return false;
    }

    public function touchRoot(): void
    {
        if ($this->filesystem->exists($this->rootFilename)) {
            $this->filesystem->touch($this->rootFilename);

            $root = $this->redis->get($this->redisRootKey);
            if ($root = $root ? \json_decode($root, true) : null) {
                $modifiedSince = @\filemtime($this->rootFilename) ?: \time();
                $root['modified_since'] = $modifiedSince;
                $this->redis->set($this->redisRootKey, \json_encode($root));
            }
        }
    }

    public function hasPackage(string $package, string $hash = null): bool
    {
        $filename = $this->packageDir . $this->packageKey($package, $hash);
        return $this->filesystem->exists($filename);
    }

    public function packageModifiedSince(string $package): ?int
    {
        $filename = $this->packageDir . $this->packageKey($package);
        return $this->filesystem->exists($filename) ? (@\filemtime($filename) ?: null) : null;
    }

    public function dumpPackage(string $package, string|array|null $content, ?string $hash = null): void
    {
        $content = \is_array($content) ? \json_encode($content, \JSON_UNESCAPED_SLASHES) : $content;
        $content = $content ? $this->encode($content) : $content;
        if (empty($content)) {
            return;
        }

        $filename = $this->packageDir . $this->packageKey($package, $hash);
        $this->filesystem->dumpFile($filename, $content);

        if ($hash !== null) {
            $filename = $this->packageDir . $this->packageKey($package);
            $this->filesystem->dumpFile($filename, $content);
        }
    }

    public function hasProvider(string $uri): bool
    {
        $filename = $this->providersDir . $this->providerKey($uri);

        return $this->filesystem->exists($filename);
    }

    public function dumpProvider(string $uri, string|array $content): void
    {
        $content = \is_array($content) ? \json_encode($content, JSON_UNESCAPED_SLASHES) : $content;

        $content = $this->encode($content);
        $filename = $this->providersDir . $this->providerKey($uri);
        $this->filesystem->dumpFile($filename, $content);
    }

    public function lookupAllProviders(ProxyOptions $config = null): iterable
    {
        $config ??= $this->getConfig();
        if ($config->getRootProviders()) {
            yield $config->getRootProviders();
        }

        foreach ($config->getProviderIncludes(true) as $provider) {
            $filename = $this->providersDir . $this->providerKey($provider);
            if ($this->filesystem->exists($filename)) {
                $content = \file_get_contents($filename);
                $content = $content ? $this->decode($content) : null;
                $content = $content ? \json_decode($content, true) : [];
                yield $content['providers'] ?? [];
            }
        }
    }

    public function getRootDir(): string
    {
        return $this->mirrorRepoMetaDir;
    }

    public function getStats(): array
    {
        $stats = $this->redis->get($this->redisStatKey);
        $stats = $stats ? \json_decode($stats, true) : [];

        return \is_array($stats) ? $stats : [];
    }

    public function clearAll(): void
    {
        $this->clearStats();
        $this->redis->del($this->redisRootKey);
    }

    public function clearStats(array $stats = []): void
    {
        $this->redis->set($this->redisStatKey, \json_encode($stats));
    }

    public function setStats(array $stats = []): void
    {
        $stats = \array_merge($this->getStats(), $stats);

        $this->redis->set($this->redisStatKey, \json_encode($stats, \JSON_UNESCAPED_SLASHES));
    }

    public function getPackageManager(): RemotePackagesManager
    {
        return $this->rpm;
    }

    public function getDownloadManager(): ZipballDownloadManager
    {
        return $this->zipballManager;
    }

    public function packageKey(string $package, string $hash = null): string
    {
        @[$vendor, $pkg] = \explode('/', $package, 2);

        $vendor = $this->safeName($vendor);
        $pkg = $this->safeName($pkg) ?: '_null_';

        return $vendor . $this->ds . $pkg . ($hash ? self::HASH_SEPARATOR . $hash : '') . '.json.gz';
    }

    public function providerKey(string $uri): string
    {
        return  $this->safeName($uri) . '.json.gz';
    }

    private function safeName($name)
    {
        $name = (string) $name;
        $name = \str_replace('.', '_', $name);

        return \preg_replace('#[^a-z0-9-_]#i', '-', $name);
    }
}
