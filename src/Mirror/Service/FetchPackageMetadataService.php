<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Composer\Util\Http\Response;
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

    public function fetchPackageMetadata(iterable $packages, ProxyOptions|RemoteProxyRepository $config, bool $writeStorage = true, iterable $providers = []): array
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

        if ($apiUrl = $config->getMetadataV2Url()) {
            $this->requestMetadataVia2($http, $packages, $apiUrl, $onFulfilled);
        } else if ($apiUrl = $config->getMetadataV1Url()) {
            $this->requestMetadataVia1($http, $packages, $apiUrl, $onFulfilled, null, $providers);
        }

        $result = [];
        foreach ($pool as $package => $meta) {
            $result[$package] = $meta;
        }

        $output = [];
        foreach ($result as $package => $meta) {
            if (!$data = ($meta['packages'][$package] ?? null)) {
                continue;
            }
            $output[$package] = $data;
        }

        return $output;
    }
}
