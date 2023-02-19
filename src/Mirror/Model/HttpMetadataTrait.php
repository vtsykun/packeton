<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

use Composer\Downloader\TransportException;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Packeton\Composer\Util\SignalLoop;
use Seld\Signal\SignalHandler;

trait HttpMetadataTrait
{
    protected ?SignalHandler $signal = null;

    /**
     * @throws TransportException
     */
    private function requestMetadataVia2(HttpDownloader $downloader, iterable $packages, string $url, callable $onFulfilled, callable $reject = null): void
    {
        $queue = new \ArrayObject();
        $loop = new SignalLoop($downloader, $this->signal);

        $promise = [];

        $reject ??= static function ($e) {
            if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        };

        foreach ($packages as $package) {
            $requester = function (Response $response) use ($package, &$queue, &$onFulfilled) {
                if (isset($queue[$package])) {
                    $metadata = $this->metadataMinifier->expand($queue[$package], $response->decodeJson());
                    unset($queue[$package]);

                    $onFulfilled($package, $metadata);
                } else {
                    $queue[$package] = $response->decodeJson();
                }
            };

            $promise[] = $downloader->add(\str_replace('%package%', $package, $url))->then($requester, $reject);
            $promise[] = $downloader->add(\str_replace('%package%', $package . '~dev', $url))->then($requester, $reject);
        }

        $loop->wait($promise);
    }

    private function requestMetadataVia1(HttpDownloader $downloader, iterable $packages, string $url, callable $onFulfilled, callable $reject = null, iterable $providersGenerator = []): void
    {
        $resolvedPackages = $promise = [];
        $loop = new SignalLoop($downloader, $this->signal);

        $reject ??= static function ($e) {
            if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        };

        $reflect = new \ReflectionFunction($onFulfilled);
        $refParameter = $reflect->getParameters()[1] ?? null;
        $asArray = $refParameter && $refParameter->getType()?->getName() === 'array';

        foreach ($providersGenerator as $providers) {
            foreach ($packages as $package) {
                if (isset($providers[$package])) {
                    $packUrl = \str_replace(['%package%', '%hash%'], [$package, $hash ?? ''], $url);
                    $resolvedPackages[$package] = [\str_replace('$', '', $packUrl), $providers[$package]['sha256'] ?? null];
                }
            }
        }

        foreach ($packages as $package) {
            if (!isset($resolvedPackages[$package])) {
                $packUrl = \str_replace(['%package%', '%hash%'], [$package, ''], $url);
                $resolvedPackages[$package] = [\str_replace('$', '', $packUrl), null];
            }
        }

        foreach ($resolvedPackages as $package => [$packageUrl, $hash]) {
            $requester = function (Response $response) use ($package, $onFulfilled, $hash, $asArray) {
                $response->getBody() ? $onFulfilled($package, $asArray ? $response->decodeJson() : $response, $hash) : null;
            };
            $promise[] = $downloader->add($packageUrl)->then($requester, $reject);
        }

        $loop->wait($promise);
    }
}
