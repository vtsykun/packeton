<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Packeton\Composer\IO\DebugIO;
use Packeton\Composer\Util\SignalLoop;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\RemoteProxyRepository;
use Seld\Signal\SignalHandler;

class SyncProviderService
{
    private ?IOInterface $io;
    private $onRejected;
    private $signal;

    public function __construct(
        private readonly ProxyHttpDownloader $downloader,
    ) {
    }

    public function setErrorHandler(callable $onRejected = null): void
    {
        $this->onRejected = $onRejected;
    }

    public function setSignalHandler(SignalHandler $signal = null): void
    {
        $this->signal = $signal;
    }

    public function setIO(?IOInterface $io = null): void
    {
        $this->io = $io;
    }

    public function reset(): void
    {
        $this->signal = null;
        $this->onRejected = null;
        $this->io = null;
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

    public function loadProvidersInclude(RemoteProxyRepository $repo, array $providerIncludes): array
    {
        $loopCallback = function ($name, $uri, HttpDownloader $http) use ($repo) {
            if ($repo->hasProvider($uri)) {
                return null;
            }

            return $http
                ->add($repo->getUrl($uri))
                ->then(fn (Response $rs) => $repo->dumpProvider($uri, $rs->getBody()), $this->onRejected);
        };

        return $this->httpLoop($providerIncludes, $repo->getConfig(), $loopCallback);
    }

    public function loadPackages(RemoteProxyRepository $repo, iterable $providers, string $providerUrl = null): array
    {
        $loopCallback = function ($name, $provider, HttpDownloader $http) use ($repo, $providerUrl) {
            $hash = $provider['sha256'] ?? null;
            $uri = $providerUrl ? \str_replace(['%package%', '%hash%'], [$name, (string)$hash], $providerUrl) :
                $repo->getConfig()->getMetadataV1Url($name, (string)$hash);

            if ($hash !== null && $repo->hasPackage($name, $hash)) {
                return null;
            }

            $onRejected = null;
            if (null !== $this->onRejected) {
                $onRejected = function ($e) use ($name) {
                    return ($this->onRejected)($e, $name);
                };
            }

            return $http->add($repo->getUrl($uri))
                ->then(fn (Response $rs) => $repo->dumpPackage($name, $rs->getBody(), $hash), $onRejected);
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
        $loop = new SignalLoop($http, $this->signal);
        $progress = $this->io instanceof ConsoleIO ? $this->io->getProgressBar() : null;

        if ($this->io instanceof DebugIO) {
            $progress = null;
        }

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
