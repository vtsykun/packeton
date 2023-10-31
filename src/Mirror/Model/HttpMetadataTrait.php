<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Packeton\Composer\Util\SignalLoop;
use Seld\Signal\SignalHandler;

trait HttpMetadataTrait
{
    protected ?SignalHandler $signal = null;

    private $errorMap = [];

    /**
     * @throws TransportException
     */
    private function requestMetadataVia2(HttpDownloader $downloader, iterable $packages, string $url, callable $onFulfilled, callable $onReject = null, callable $lastModifyLoader = null): void
    {
        $queue = new \ArrayObject();
        $loop = new SignalLoop($downloader, $this->signal);

        $promise = [];

        $onReject ??= static function ($e, $package) {
            if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        };

        foreach ($packages as $package) {
            $requester = function (Response $response) use ($package, &$queue, &$onFulfilled) {
                if ($response->getStatusCode() > 299) {
                    return;
                }

                try {
                    $body = $response->decodeJson();
                } catch (\Throwable $e) {
                    $this->handleUnexpectedException($e);
                    return;
                }

                if (isset($queue[$package])) {
                    $metadata = $this->metadataMinifier->expand($queue[$package], $body);
                    unset($queue[$package]);

                    $onFulfilled($package, $metadata);
                } else {
                    $queue[$package] = $body;
                }
            };

            $reject = function ($e) use ($package, $onReject) {
                return $onReject($e, $package);
            };

            $options = [];
            if ($lastModified = $lastModifyLoader ? $lastModifyLoader($package) : null) {
                $headers = ['If-Modified-Since: '.$lastModified];
                $options = ['http' => ['header' => $headers]];
            }

            $promise[] = $downloader->add(\str_replace('%package%', $package, $url), $options)->then($requester, $reject);
            $promise[] = $downloader->add(\str_replace('%package%', $package . '~dev', $url), $options)->then($requester, $reject);
        }

        $loop->wait($promise);

        // Try without last modify check
        if ($lastModifyLoader && $queue->count() > 0) {
            $packages = \array_keys($queue->getArrayCopy());
            $this->requestMetadataVia2($downloader, $packages, $url, $onFulfilled, $onReject);
        }
    }

    private function requestMetadataVia1(HttpDownloader $downloader, iterable $packages, string $url, callable $onFulfilled, callable $onReject = null, iterable $providers = []): void
    {
        $resolvedPackages = $promise = [];
        $loop = new SignalLoop($downloader, $this->signal);

        $onReject ??= static function ($e, $package) {
            if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        };

        $reflect = new \ReflectionFunction($onFulfilled);
        $refParameter = $reflect->getParameters()[1] ?? null;
        $asArray = $refParameter && $refParameter->getType()?->getName() === 'array';

        foreach ($providers as $provider) {
            foreach ($packages as $package) {
                if (isset($provider[$package])) {
                    $hash = $provider[$package]['sha256'] ?? null;
                    $packUrl = \str_replace(['%package%', '%hash%'], [$package, $hash ?: ''], $url);
                    $packUrl = empty($hash) ? \str_replace('$', '', $packUrl) : $packUrl;
                    $resolvedPackages[$package] = [$packUrl, $hash];
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
            $promise[] = $downloader->add($packageUrl)->then(
                function (Response $response) use ($package, $onFulfilled, $hash, $asArray) {
                    $response->getBody() ? $onFulfilled($package, $asArray ? $response->decodeJson() : $response, $hash) : null;
                },
                function ($e) use ($package, $onReject) {
                    return $onReject($e, $package);
                }
            );
        }

        $loop->wait($promise);
    }

    private function onErrorIgnore(IOInterface $io, $skipAllErrors = true): callable
    {
        return function ($e, $package = null) use ($io, $skipAllErrors) {
            if ($e instanceof TransportException) {
                $code = $e->getStatusCode();
                if (\in_array($code, [401, 403], true)) {
                    throw $e;
                }

                $this->errorMap[$code] = ($this->errorMap[$code] ?? 0) + 1;
                if ($this->errorMap[$code] < 12) {
                    $code === 404 ? $io->warning("Not found [$package] " . $e->getMessage()) : $io->error("[$code] [$package] " . $e->getMessage());
                } else if ($this->errorMap[$code] === 12) {
                    $io->error("A lot of exceptions [$code]. Skip logging.");
                }

                return false;
            }

            if ($e instanceof \Throwable) {
                if ($skipAllErrors === true) {
                    $value = $this->errorMap[-1] = ($this->errorMap[-1] ?? 0) + 1;
                    $io->critical($e->getMessage());

                    // Skip sync if a lot of exception.
                    if ($value >= 12) {
                        throw $e;
                    }

                    return false;
                }
                throw $e;
            }

            return false;
        };
    }

    private function handleUnexpectedException($e): void
    {
        if ($e instanceof \Throwable) {
            throw $e;
        }
    }
}
