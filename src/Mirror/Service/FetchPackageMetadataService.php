<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Composer\Downloader\TransportException;
use Composer\Util\Http\Response;
use Composer\Util\Loop;
use Packeton\Composer\MetadataMinifier;
use Packeton\Mirror\Exception\MetadataNotFoundException;
use Packeton\Mirror\Model\HttpMetadataTrait;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\RemoteProxyRepository;

class FetchPackageMetadataService
{
    use HttpMetadataTrait;

    public function __construct(
        protected SyncProviderService $syncService,
        protected MetadataMinifier $metadataMinifier
    ) {
    }

    public function fetchPackageMetadata(iterable $packages, ProxyOptions|RemoteProxyRepository $config, bool $writeStorage = true): array
    {
        $repo = null;
        if ($config instanceof RemoteProxyRepository) {
            if (null === $config->rootMetadata()) {
                try {
                    $root = $this->syncService->loadRootComposer($config);
                } catch (\RuntimeException $e) {
                    throw new MetadataNotFoundException($e->getMessage(), 404, $e);
                }

                $config->dumpRootMeta($root);
            }

            $repo = $config;
            $config = $config->getConfig();
        }

        $pool = new \ArrayObject();
        $onFulfilled = static function ($name, $meta, $hash = null) use (&$pool, $repo, $writeStorage) {
            $body = $meta;
            if ($meta instanceof Response) {
                $body = $meta->getBody();
                $meta = $meta->decodeJson();
            }

            $pool->offsetSet($name, $meta);
            if (null !== $repo && true === $writeStorage) {
                $repo->dumpPackage($name, $body, $hash);
            }
        };

        $http = $this->syncService->initHttpDownloader($config);

        if ($config->hasV2Api()) {
            $apiUrl =  $config->getMetadataV2Url();
            $this->requestMetadataVia2($http, $packages, $apiUrl, $onFulfilled);
        } else if ($config->getMetadataV1Url()) {
            $reject = static function ($e) {
                if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                    return false;
                }
                throw $e;
            };

            $resolvedPackages = [];

            if (null !== $repo) {
                foreach ($repo->lookupAllProviders() as $providers) {
                    foreach ($packages as $package) {
                        if (isset($providers[$package])) {
                            $url = $config->getMetadataV1Url($package, $providers[$package]['sha256'] ?? '');
                            $resolvedPackages[$package] = [\str_replace('$', '', $url), $providers[$package]['sha256'] ?? null];
                        }
                    }
                }
            }

            foreach ($packages as $package) {
                if (!isset($resolvedPackages[$package])) {
                    $url = $config->getMetadataV1Url($package, '');
                    $resolvedPackages[$package] = [\str_replace('$', '', $url), null];
                }
            }

            $loop = new Loop($http);
            $promise = [];
            foreach ($resolvedPackages as $package => [$packageUrl, $hash]) {
                $requester = function (Response $response) use ($package, $onFulfilled, $hash) {
                    $onFulfilled($package, $response, $hash);
                };
                $promise[] = $http->add($packageUrl)->then($requester, $reject);
            }

            $loop->wait($promise);
        }

        $result = [];
        foreach ($pool as $package => $meta) {
            $result[$package] = $meta;
        }

        return $result;
    }
}
