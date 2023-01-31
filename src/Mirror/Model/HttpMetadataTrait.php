<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

use Composer\Downloader\TransportException;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Packeton\Composer\MetadataMinifier;

trait HttpMetadataTrait
{
    protected MetadataMinifier $metadataMinifier;

    /**
     * @throws TransportException
     */
    private function requestMetadataVia2(HttpDownloader $downloader, iterable $packages, string $url, callable $onFulfilled): void
    {
        $queue = new \ArrayObject();
        $loop = new Loop($downloader);

        $promise = [];
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

            $promise[] = $downloader->add(\str_replace('%package%', $package, $url))->then($requester);
            $promise[] = $downloader->add(\str_replace('%package%', $package . '~dev', $url))->then($requester);
        }

        $loop->wait($promise);
    }
}
