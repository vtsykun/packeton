<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Composer\IO\IOInterface;
use Composer\Util\Http\Response;
use Packeton\Composer\MetadataMinifier;
use Packeton\Mirror\Model\HttpMetadataTrait;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\RemoteProxyRepository as RPR;
use Seld\Signal\SignalHandler;

class RemoteSyncProxiesFacade
{
    use HttpMetadataTrait;

    public const FULL_RESET = 1;
    public const UI_RESET = 2;

    protected $providersCacheKeys = [];

    public function __construct(
        protected SyncProviderService $syncProvider,
        protected MetadataMinifier $metadataMinifier
    ) {
    }

    public function sync(RPR $repo, IOInterface $io, int $flags = 0, ?SignalHandler $signal = null): array
    {
        $this->syncProvider->setIO($io);
        $this->syncProvider->setSignalHandler($signal);
        $this->signal = $signal;

        try {
            return $this->doSync($repo, $io, $flags);
        } finally {
            $this->signal = null;
            $this->syncProvider->reset();
            $this->errorMap = [];
        }
    }

    private function doSync(RPR $repo, IOInterface $io, int $flags = 0): array
    {
        $this->providersCacheKeys = [];

        $stats = ['last_sync' => \date('Y-m-d H:i:s').' UTC'];
        if (self::FULL_RESET & $flags) {
            $io->notice('Remove all sync data and execute full resync!');

            // use ProcessExecutor for fast remove rm -rf
            $cfs = new \Composer\Util\Filesystem();
            $cfs->remove($repo->getRootDir());

            $repo->clearAll();
            $io->notice('All data removed!');
        }

        $io->info('Loading root file packages.json');
        $root = $this->syncProvider->loadRootComposer($repo);
        $config = $repo->getConfig()->withRoot($root);

        if ($this->signal?->isTriggered()) {
            return [];
        }

        if ($config->isLazy() && $config->hasV2Api()) {
            $stats += $this->syncLazyV2($repo, $io, $config, $updated);
            if ($updated > 0) {
                $repo->dumpRootMeta($root);
            }

            return $stats;
        }

        if ($config->hasV2Api()) {
            $io->info("Sync enabled packages via composer v2 API");
            $stats += $this->syncLazyV2($repo, $io, $config, $updated);
        }

        if ($repo->isRootFresh($root) && 0 === ($flags & self::UI_RESET)) {
            $io->info('Root is not changes, skip update providers');
            return $stats;
        }

        $providersForUpdate = [];
        if ($providerIncludes = $config->getProviderIncludes(true)) {
            $io->info('Loading provider includes...');

            $this->providersCacheKeys = \array_merge($this->providersCacheKeys, \array_map($repo->providerKey(...), $providerIncludes));
            $this->syncProvider->setErrorHandler($this->onErrorIgnore($io, false));
            [$providersForUpdate, ] = $this->syncProvider->loadProvidersInclude($repo, $providerIncludes);
        } else {
            $io->info('Not found any provider-includes in packages.json');
        }

        if ($includes = \array_keys($config->getIncludes())) {
            $io->info('Loading includes. ' . \implode(' ', $includes));

            $this->providersCacheKeys = \array_merge($this->providersCacheKeys, \array_map($repo->providerKey(...), $includes));
            $this->syncProvider->loadProvidersInclude($repo, $includes);
        }

        $availablePackages = \array_merge(
            $this->loadRootPackagesNames($root, $repo),
            $this->loadProviderPackagesNames($repo, $config->maxCountOfAvailablePackages(), $config)
        );

        $stats['pkg_total'] = $availableCount = \count($availablePackages);
        $stats['available_packages'] = $availableCount < $config->maxCountOfAvailablePackages() ? $availablePackages : [];

        $providerUrl = $config->getMetadataV1Url();

        if (empty($providerUrl) || $config->isLazy()) {
            $repo->dumpRootMeta($root);
            if ($providerUrl) {
                $io->info("Sync enabled packages via composer v1 api $providerUrl");
                $stats += $this->syncLazyV1($repo, $io, $config);
            }

            return $stats;
        }

        $updated = 0;

        $this->syncProvider->setErrorHandler($this->onErrorIgnore($io));
        foreach ($this->getAllProviders($providersForUpdate, $repo, $config) as $provName => $providersChunk) {
            $io->info("Loading chunk #$provName");

            [$packages] = $this->syncProvider->loadPackages($repo, $providersChunk, $providerUrl);
            $updated += \count($packages);
            $io->info("Total updated $updated packages.");
        }

        $stats += ['pkg_updated' => $updated];
        $repo->dumpRootMeta($root);

        return $stats;
    }

    private function loadProviderPackagesNames(RPR $repo, int $limit, ProxyOptions $config): array
    {
        $packages = [];
        foreach ($repo->lookupAllProviders($config) as $providerInclude) {
            $packages = \array_merge($packages, \array_keys($providerInclude));
            if (\count($packages) > $limit) {
                break;
            }
        }

        return $packages;
    }

    private function loadRootPackagesNames(array $data, RPR $repo): array
    {
        $packages = [];
        // legacy repo handling
        if (!isset($data['packages']) && !isset($data['includes'])) {
            foreach ($data as $pkg) {
                if (isset($pkg['versions']) && \is_array($pkg['versions'])) {
                    foreach ($pkg['versions'] as $metadata) {
                        $packages[] = $metadata['name'] ?? null;
                    }
                }
            }

            return \array_values(array_unique(array_filter($packages)));
        }

        if (\is_array($data['packages'] ?? null)) {
            foreach ($data['packages'] as $package => $versions) {
                $packages[] = \strtolower((string) $package);
            }
        }

        if (\is_array($data['includes'] ?? null)) {
            foreach ($data['includes'] as $include => $metadata) {
                $includedData = $repo->findProviderMetadata($include)?->decodeJson() ?: [];
                $packages = \array_merge($packages, $this->loadRootPackagesNames($includedData, $repo));
            }
        }

        return \array_values(array_unique(array_filter($packages)));
    }

    private function getAllProviders($providersForUpdate, RPR $repo, ProxyOptions $config): iterable
    {
        if ($config->getRootProviders()) {
            yield 'root' => $config->getRootProviders();
        }

        foreach ($providersForUpdate as $uri) {
            $providers = $repo->findProviderMetadata($uri)?->decodeJson() ?:[];
            $providerChunks = \array_chunk($providers['providers'] ?? [], 1000, true);
            foreach ($providerChunks as $i => $providerChunk) {
                yield "Chunk #$i => $uri" => $providerChunk;
            }
        }
    }

    private function syncLazyV1(RPR $repo, IOInterface $io, ProxyOptions $config): array
    {
        $stats = [];
        $rmp = $repo->getPackageManager();
        $packages = $rmp->getEnabled();

        $http = $this->syncProvider->initHttpDownloader($config);
        $onFulfilled = static function (string $name, Response $meta, $hash = null) use ($repo) {
            $repo->dumpPackage($name, $meta->getBody());
            if (null !== $hash) {
                $repo->dumpPackage($name, $meta->getBody(), $hash);
            }
        };

        if (empty($packages)) {
            $io->info('Not found enabled packages for re sync');
            return $stats;
        }

        $this->requestMetadataVia1($http, $packages, $config->getMetadataV1Url(), $onFulfilled, null, $repo->lookupAllProviders($config));

        return $stats;
    }

    private function syncLazyV2(RPR $repo, IOInterface $io, ProxyOptions $config, &$updated = null): array
    {
        $stats = [];
        $rmp = $repo->getPackageManager();
        $packages = $rmp->getEnabled();

        $modifiedSinceLoader = static function($package) use ($repo) {
            if ($since = $repo->packageModifiedSince($package)) {
                return (new \DateTime("@$since", new \DateTimeZone('UTC')))->format('D, d M Y H:i:s').' GMT';
            }
            return null;
        };

        $http = $this->syncProvider->initHttpDownloader($config);
        if ($apiUrl = $config->getV2SyncApi()) {
            $stats['metadata_timestamp'] = \time() * 10000;
            $timestamp = $repo->getStats()['metadata_timestamp'] ?? 0;

            try {
                $response = $http->get("$apiUrl?since=$timestamp");
                $modifiedSinceLoader = null;
            } catch (\Throwable $e) {
                $io->warning($e->getMessage());
                $response = null;
            }

            if ($response && $response->getStatusCode() === 200) {
                $response = $response->decodeJson();
                $timestamp = $response['timestamp'] ?? \time() * 10000;
                $stats['metadata_timestamp'] = $timestamp;
                $actions = $response['actions'] ?? [];
                if (empty($actions) && !isset($response['error'])) {
                    return $stats;
                }

                if ('resync' !== ($actions[0]['type'] ?? null)) {
                    $resync = \array_column($actions, 'package');
                    $resync = \array_map(fn ($p) => \preg_replace('/~dev$/', '', $p), $resync);
                    $packages = \array_intersect($packages, $resync);
                }
            }
        }

        if (empty($packages)) {
            $io->info('Not found packages for resync');
            return $stats;
        }

        $updated = 0;
        $onFulfilled = static function (string $name, $meta) use ($repo, &$updated) {
            $updated++;
            $repo->dumpPackage($name, $meta);
        };

        $this->requestMetadataVia2($http, $packages, $config->getMetadataV2Url(), $onFulfilled, $this->onErrorIgnore($io), $modifiedSinceLoader);

        $io->info('Updated packages: ' . $updated);

        return $stats;
    }

    public function clear(): void
    {
        $this->providersCacheKeys = [];
    }
}
