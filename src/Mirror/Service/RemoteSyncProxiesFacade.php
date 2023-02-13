<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Packeton\Composer\MetadataMinifier;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\RemoteProxyRepository as RPR;

class RemoteSyncProxiesFacade
{
    public const FULL_RESET = 1;

    protected $providersCacheKeys = [];

    public function __construct(
        private readonly SyncProviderService $syncProvider,
        private readonly MetadataMinifier $minifier
    ) {
    }

    public function sync(RPR $repo, IOInterface $io, int $flags = 0): array
    {
        $this->syncProvider->setIO($io);
        $this->providersCacheKeys = [];

        $stats = ['last_sync' => \date('Y-m-d H:i:s')];
        if (self::FULL_RESET & $flags) {
            $io->notice('Remove all sync data and execute full resync!');

            // use ProcessExecutor for fast remove rm -rf
            $cfs = new \Composer\Util\Filesystem();
            $cfs->remove($repo->getRootDir());

            $repo->clearStats();
            $io->notice('All data removed!');
        }

        $io->info('Loading root file packages.json');
        $root = $this->syncProvider->loadRootComposer($repo);
        $config = $repo->getConfig()->withRoot($root);

        if ($config->isLazy() && $config->hasV2Api()) {
            $stats = $this->syncLazy($repo, $io, $config);
            $repo->dumpRootMeta($root);
            return $stats;
        }

        if ($repo->isRootFresh($root)) {
            $io->info('Root is not changes, skip update providers');
            return $stats;
        }

        $providersForUpdate = [];
        if ($providerIncludes = $config->getProviderIncludes(true)) {
            $io->info('Loading provider includes...');

            $this->providersCacheKeys = \array_merge($this->providersCacheKeys, \array_map($repo->providerKey(...), $providerIncludes));
            [$providersForUpdate, ] = $this->syncProvider->loadProvidersInclude($repo, $providerIncludes);
        } else {
            $io->info('Not found any provider-includes in packages.json');
        }

        if ($includes = \array_keys($config->getIncludes())) {
            $io->info('Loading includes. ' . implode(' ', $includes));

            $this->providersCacheKeys = \array_merge($this->providersCacheKeys, \array_map($repo->providerKey(...), $includes));
            $this->syncProvider->loadProvidersInclude($repo, $includes);
        }

        $availablePackages = array_merge(
            $this->loadRootPackagesNames($root, $repo),
            $this->loadProviderPackagesNames($repo, $config->maxCountOfAvailablePackages())
        );


        $stats['available_packages'] = \count($availablePackages) < $config->maxCountOfAvailablePackages() ? $availablePackages : [];

        $providerUrl = $config->getMetadataV1Url();

        if (empty($providerUrl) || $config->isLazy()) {
            $io->notice('Skipping sync packages, lazy sync.');
            $repo->dumpRootMeta($root);
            return $stats;
        }

        $updated = $success = 0;
        $maxErrors = 3;
        foreach ($this->getAllProviders($providersForUpdate, $repo, $config) as $provName => $providersChunk) {
            $io->info("Loading chunk #$provName");

            try {
                [$packages, $skipped] = $this->syncProvider->loadPackages($repo, $providersChunk, $providerUrl);
            } catch (TransportException $e) {
                $io->error($e->getMessage());
                $io->warning("Skip chunk #$provName");
                \sleep(2);

                if ($maxErrors--) {
                    continue;
                } else {
                    break;
                }
            }

            $updated += \count($packages);
            $success += \count($packages) + \count($skipped);
            $io->info("Total updated $updated packages.");
        }

        $stats += ['pkg_updated' => $updated, 'pkg_total' => $success];
        $repo->dumpRootMeta($root);

        return $stats;
    }

    private function loadProviderPackagesNames(RPR $repo, int $limit): array
    {
        $packages = [];
        foreach ($repo->lookupAllProviders() as $providerInclude) {
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

            return array_values(array_unique(array_filter($packages)));
        }

        if (isset($data['packages'])) {
            foreach ($data['packages'] as $package => $versions) {
                $packages[] = \strtolower((string) $package);
            }
        }

        if (isset($data['includes'])) {
            foreach ($data['includes'] as $include => $metadata) {
                $includedData = $repo->findProviderMetadata($include)?->decodeJson() ?: [];
                $packages = \array_merge($packages, $this->loadRootPackagesNames($includedData, $repo));
            }
        }

        return array_values(array_unique(array_filter($packages)));
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

    private function syncLazy(RPR $repo, IOInterface $io, ProxyOptions $config): array
    {
        $stats = [];
        $packages = $repo->getPackageManager()->getEnabled();

        $http = $this->syncProvider->initHttpDownloader($config);
        if ($apiUrl = $config->getV2SyncApi()) {
            $timestamp = $repo->getStats()['metadata_timestamp'] ?? 0;
            $response = $http->get("$apiUrl?since=$timestamp");

            if ($response->getStatusCode() === 200) {
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
            $io->info('not found packages for resync');
            return $stats;
        }

        $minifier = function ($metadata) {
            $metadata = \is_string($metadata) ? \json_decode($metadata, true) : $metadata;
            return $this->minifier->expand($metadata);
        };

        $packages = \array_flip($packages);

        // Add delete handler.
        [$updated] = $this->syncProvider->loadPackages($repo, $packages, $config->getMetadataV2Url(), $minifier);
        $io->info('Updated packages: ' . \count($updated));

        return $stats;
    }

    public function clear(): void
    {
        $this->providersCacheKeys = [];
    }
}
