<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\RemoteProxyRepository;

class SyncProviderService
{
    private ?IOInterface $io;

    public function __construct(
        private readonly ProxyHttpDownloader $downloader,
    ) {
    }

    public function loadRootComposer(RemoteProxyRepository $repo): array
    {
        $config = $repo->getConfig();
        $http = $this->initHttpDownloader($config);

        $response = $http->get($config->getUrl('packages.json'));
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('Unable to load packages.json from ' . $config->getUrl() . '. Error:' . $response->getBody());
        }

        return $response->decodeJson();
    }

    public function setIO(?IOInterface $io = null): void
    {
        $this->io = $io;
    }

    public function loadProvidersInclude(RemoteProxyRepository $repo, array $providerIncludes): array
    {
        $loopCallback = function ($name, $uri, HttpDownloader $http) use ($repo) {
            if ($repo->hasProvider($uri)) {
                return null;
            }

            return $http
                ->add($repo->getUrl($uri))
                ->then(fn (Response $rs) => $repo->dumpProvider($uri, $rs->getBody()));
        };

        return $this->httpLoop($providerIncludes, $repo->getConfig(), $loopCallback);
    }

    public function loadPackages(RemoteProxyRepository $repo, iterable $providers, string $providerUrl = null, callable $minifier = null): array
    {
        $loopCallback = function ($name, $provider, HttpDownloader $http) use ($repo, $providerUrl, $minifier) {
            $hash = $provider['sha256'] ?? null;
            $uri = $providerUrl ? \str_replace(['%package%', '%hash%'], [$name, (string)$hash], $providerUrl) :
                $repo->getConfig()->getMetadataV1Url($name, (string)$hash);

            if ($repo->hasPackage($name, $hash)) {
                return null;
            }

            return $http->add($repo->getUrl($uri))
                ->then(fn (Response $rs) => $repo->dumpPackage($name, $minifier ? $minifier($rs->getBody()) : $rs->getBody(), $hash));
        };

        return $this->httpLoop($providers, $repo->getConfig(), $loopCallback);
    }

    public function initHttpDownloader(ProxyOptions $options): HttpDownloader
    {
        return $this->downloader->getHttpClient($options, $this->io);
    }

    protected function httpLoop(iterable $generator, ProxyOptions $config, callable $factory): array
    {
        $http = $this->initHttpDownloader($config);
        $loop = new Loop($http);
        $progress = $this->io instanceof ConsoleIO ? $this->io->getProgressBar() : null;

        $promises = $updated = $skipped = [];
        foreach ($generator as $key => $value) {
            if ($promise = $factory($key, $value, $http)) {
                $promises[] = $promise;
                $updated[$key] = $value;
            } else {
                $skipped[$key] = $value;
            }
        }

        $loop->wait($promises, $progress);

        return [$updated, $skipped];
    }
}
