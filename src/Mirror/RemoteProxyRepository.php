<?php

declare(strict_types=1);

namespace Packeton\Mirror;

use League\Flysystem\FilesystemOperator;
use Packeton\Composer\Util\ConfigFactory;
use Packeton\Mirror\Model\GZipTrait;
use Packeton\Mirror\Model\JsonMetadata;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\Model\RepoCaps;
use Packeton\Mirror\Service\RemotePackagesManager;
use Packeton\Mirror\Service\ZipballDownloadManager;
use Packeton\Mirror\Utils\ApiMetadataUtils;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Filesystem mirror proxy repo for metadata with optional S3 storage support.
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
    protected string $repoName;

    public function __construct(
        protected array $repoConfig,
        protected ?string $mirrorRepoMetaDir,
        protected Filesystem $filesystem,
        protected \Redis $redis,
        protected RemotePackagesManager $rpm,
        protected ZipballDownloadManager $zipballManager,
        protected FilesystemOperator $mirrorMetaStorage,
        protected ?string $mirrorMetaCacheDir = null,
    ) {
        if (null === $mirrorRepoMetaDir) {
            $mirrorRepoMetaDir = ConfigFactory::getHomeDir();
        }

        $this->ds = DIRECTORY_SEPARATOR;
        $this->repoName = $this->repoConfig['name'];

        $this->mirrorRepoMetaDir = \rtrim($mirrorRepoMetaDir, $this->ds) . $this->ds . $this->repoName;
        $this->rootFilename = $this->mirrorRepoMetaDir . $this->ds . self::ROOT_PACKAGE;
        $this->providersDir = $this->mirrorRepoMetaDir . $this->ds . 'p' . $this->ds;
        $this->packageDir = $this->mirrorRepoMetaDir . $this->ds . 'package' . $this->ds;

        $this->redisRootKey = "proxy-root-{$this->repoName}";
        $this->redisStatKey = "proxy-info-{$this->repoName}";

        $this->zipballManager->setRepository($this);
    }

    /**
     * @param string|null $path
     * @return string
     */
    public function getUrl(?string $path = null): string
    {
        return $this->getConfig()->getUrl($path);
    }

    /**
     * {@inheritdoc}
     */
    public function rootMetadata(?int $modifiedSince = null): ?JsonMetadata
    {
        $storageKey = $this->storageKey(self::ROOT_PACKAGE);
        $cacheFile = $this->cacheFilePath(self::ROOT_PACKAGE);

        $content = $this->readFromStorage($storageKey, $cacheFile);
        if ($content !== null) {
            return new JsonMetadata($content, \time(), null, $this->repoConfig);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function findProviderMetadata(string $providerName, ?int $modifiedSince = null): ?JsonMetadata
    {
        $key = 'p/' . $this->providerKey($providerName);
        $storageKey = $this->storageKey($key);
        $cacheFile = $this->cacheFilePath($key);

        $content = $this->readFromStorage($storageKey, $cacheFile);
        if ($content !== null) {
            return new JsonMetadata($content, \time(), null, $this->repoConfig);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function findPackageMetadata(string $name, ?int $modifiedSince = null): ?JsonMetadata
    {
        @[$package, $hash] = \explode('$', $name);

        $config = $this->getConfig();

        $patch = function (array $metadata) use ($package) {
            if ($patchData = $this->getPackageManager()->getPatchMetadata($package)) {
                return ApiMetadataUtils::applyMetadataPatchV1($package, $metadata, $patchData);
            }
            return $metadata;
        };

        // Satis API. Check includes without loading big data
        if ($config->matchCaps([RepoCaps::INCLUDES, RepoCaps::PACKAGES])) {
            if ($modifiedSince && $config->lastModifiedUnix() <= $modifiedSince) {
                return JsonMetadata::createNotModified($config->lastModifiedUnix());
            }

            $root = $this->rootMetadata()?->decodeJson() ?: [];
            if ($packages = $this->lookIncludePackageMetadata($root, $package)) {
                $content = \json_encode(['packages' => [$package => $packages]], \JSON_UNESCAPED_SLASHES);
                $unix = $config->lastModifiedUnix();
                return new JsonMetadata($content, $unix, null, $this->repoConfig, $patch);
            }
        }

        $key = 'package/' . $this->packageKey($package, $hash);
        $storageKey = $this->storageKey($key);
        $cacheFile = $this->cacheFilePath($key);

        $content = $this->readFromStorage($storageKey, $cacheFile);
        if ($content !== null) {
            return new JsonMetadata($content, \time(), $hash, $this->repoConfig, $patch);
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
            return $data['packages'][$package] ?: [];
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

    public function dumpRootMeta(array $root): void
    {
        $rootJson = \json_encode($root, \JSON_UNESCAPED_SLASHES);

        $storageKey = $this->storageKey(self::ROOT_PACKAGE);
        $cacheFile = $this->cacheFilePath(self::ROOT_PACKAGE);

        $this->writeToStorage($storageKey, $cacheFile, $rootJson);
        $this->updateRootStats($root);

        $this->proxyOptions = null;
    }

    protected function updateRootStats(?array $root = null): array
    {
        $root ??= $this->rootMetadata()?->decodeJson() ?: [];
        if ($root) {
            $modifiedSince = \time();
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
        $storageKey = $this->storageKey(self::ROOT_PACKAGE);
        $cacheFile = $this->cacheFilePath(self::ROOT_PACKAGE);

        $existingContent = $this->readFromStorage($storageKey, $cacheFile);
        if ($existingContent !== null) {
            $rootJson = \json_encode($root, \JSON_UNESCAPED_SLASHES);
            return \sha1($existingContent) === \sha1($rootJson);
        }

        return false;
    }

    public function touchRoot(): void
    {
        $root = $this->redis->get($this->redisRootKey);
        if ($root = $root ? \json_decode($root, true) : null) {
            $root['modified_since'] = \time();
            $this->redis->set($this->redisRootKey, \json_encode($root));
        }
    }

    public function hasPackage(string $package, ?string $hash = null): bool
    {
        $key = 'package/' . $this->packageKey($package, $hash);
        $cacheFile = $this->cacheFilePath($key);

        // Check local cache first
        if ($cacheFile !== null && $this->filesystem->exists($cacheFile)) {
            return true;
        }

        $storageKey = $this->storageKey($key);
        return $this->mirrorMetaStorage->fileExists($storageKey);
    }

    public function packageModifiedSince(string $package): ?int
    {
        $key = 'package/' . $this->packageKey($package);
        $cacheFile = $this->cacheFilePath($key);

        // Check local cache first for mtime
        if ($cacheFile !== null && $this->filesystem->exists($cacheFile)) {
            return @\filemtime($cacheFile) ?: null;
        }

        $storageKey = $this->storageKey($key);
        if ($this->mirrorMetaStorage->fileExists($storageKey)) {
            return $this->mirrorMetaStorage->lastModified($storageKey);
        }

        return null;
    }

    public function dumpPackage(string $package, string|array|null $content, ?string $hash = null): void
    {
        $content = \is_array($content) ? \json_encode($content, \JSON_UNESCAPED_SLASHES) : $content;
        $content = $content ? $this->encode($content) : $content;
        if (empty($content)) {
            return;
        }

        $key = 'package/' . $this->packageKey($package, $hash);
        $storageKey = $this->storageKey($key);
        $cacheFile = $this->cacheFilePath($key);

        $this->writeToStorage($storageKey, $cacheFile, $content);

        if ($hash !== null) {
            $keyWithoutHash = 'package/' . $this->packageKey($package);
            $storageKeyWithoutHash = $this->storageKey($keyWithoutHash);
            $cacheFileWithoutHash = $this->cacheFilePath($keyWithoutHash);

            $this->writeToStorage($storageKeyWithoutHash, $cacheFileWithoutHash, $content);
        }
    }

    public function setPatchData(array $data): void
    {
        if (!empty($data['metadata'])) {
            $metadata = is_string($data['metadata']) ? json_decode($data['metadata'], true) : $data['metadata'];
            $this->getPackageManager()->patchMetadata($data['package'], $data['version'], $data['strategy'], $metadata);
        } else {
            $this->getPackageManager()->unsetPatchMetadata($data['package'], $data['version']);
        }
    }

    public function hasProvider(string $uri): bool
    {
        $key = 'p/' . $this->providerKey($uri);
        $cacheFile = $this->cacheFilePath($key);

        // Check local cache first
        if ($cacheFile !== null && $this->filesystem->exists($cacheFile)) {
            return true;
        }

        $storageKey = $this->storageKey($key);
        return $this->mirrorMetaStorage->fileExists($storageKey);
    }

    public function dumpProvider(string $uri, string|array $content): void
    {
        $content = \is_array($content) ? \json_encode($content, JSON_UNESCAPED_SLASHES) : $content;
        $content = $this->encode($content);

        $key = 'p/' . $this->providerKey($uri);
        $storageKey = $this->storageKey($key);
        $cacheFile = $this->cacheFilePath($key);

        $this->writeToStorage($storageKey, $cacheFile, $content);
    }

    public function lookupAllProviders(?ProxyOptions $config = null): iterable
    {
        $config ??= $this->getConfig();
        if ($config->getRootProviders()) {
            yield $config->getRootProviders();
        }

        foreach ($config->getProviderIncludes(true) as $provider) {
            $key = 'p/' . $this->providerKey($provider);
            $storageKey = $this->storageKey($key);
            $cacheFile = $this->cacheFilePath($key);

            $content = $this->readFromStorage($storageKey, $cacheFile);
            if ($content !== null) {
                $content = $this->decode($content);
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

    public function packageKey(string $package, ?string $hash = null): string
    {
        @[$vendor, $pkg] = \explode('/', $package, 2);

        $vendor = $this->safeName($vendor);
        $pkg = $this->safeName($pkg) ?: '_null_';
        $hash = $hash ? $this->safeName($hash) : null;

        return $vendor . '/' . $pkg . ($hash ? self::HASH_SEPARATOR . $hash : '') . '.json.gz';
    }

    public function providerKey(string $uri): string
    {
        return $this->safeName($uri) . '.json.gz';
    }

    protected function storageKey(string $relativePath): string
    {
        return $this->repoName . '/' . $relativePath;
    }

    protected function cacheFilePath(string $relativePath): ?string
    {
        if ($this->mirrorMetaCacheDir === null || $this->mirrorMetaCacheDir === '') {
            return null;
        }

        return \rtrim($this->mirrorMetaCacheDir, '/') . '/' . $this->repoName . '/' . $relativePath;
    }

    protected function readFromStorage(string $storageKey, ?string $cacheFile): ?string
    {
        // Check local cache first if configured
        if ($cacheFile !== null && $this->filesystem->exists($cacheFile)) {
            return \file_get_contents($cacheFile);
        }

        // Read from remote storage
        if (!$this->mirrorMetaStorage->fileExists($storageKey)) {
            return null;
        }

        $content = $this->mirrorMetaStorage->read($storageKey);

        // Optionally write to local cache
        if ($cacheFile !== null && $content !== null) {
            $dir = \dirname($cacheFile);
            if (!$this->filesystem->exists($dir)) {
                $this->filesystem->mkdir($dir);
            }
            @\file_put_contents($cacheFile, $content);
        }

        return $content;
    }

    protected function writeToStorage(string $storageKey, ?string $cacheFile, string $content): void
    {
        // Always write to remote storage
        $this->mirrorMetaStorage->write($storageKey, $content);

        // Optionally write to local cache
        if ($cacheFile !== null) {
            $dir = \dirname($cacheFile);
            if (!$this->filesystem->exists($dir)) {
                $this->filesystem->mkdir($dir);
            }
            $this->filesystem->dumpFile($cacheFile, $content);
        }
    }

    private function safeName($name): string
    {
        $name = (string) $name;
        $name = \str_replace('.', '_', $name);

        return \preg_replace('#[^a-z0-9-_]#i', '-', $name);
    }
}
