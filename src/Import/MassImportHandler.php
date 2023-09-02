<?php

declare(strict_types=1);

namespace Packeton\Import;

use Composer\IO\IOInterface;
use Composer\Util\HttpDownloader;
use Packeton\Composer\Util\SignalLoop;
use Packeton\Form\Model\ImportRequest;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\Service\ProxyHttpDownloader;

class MassImportHandler
{
    protected const MAX_PACKAGE_LIMIT = 10000;

    protected ?IOInterface $io = null;

    public function __construct(
        protected ProxyHttpDownloader $downloader,
    ) {
    }

    public function setIO(?IOInterface $io): void
    {
        $this->io = $io;
    }

    public function getRepoUrls(ImportRequest $request): array
    {
        $repos = match ($request->type) {
            'composer' => $this->fetchComposerRepos($request->composerUrl, $request->username, $request->password, $request->packageFilter),
        };

        return [];
    }

    protected function fetchComposerRepos(string $url, string $username = null, string $password = null, string $filter = null): array
    {
        $options = $this->initComposer($url, $username, $password);
        $http = $this->downloader->getHttpClient($options, $this->io);


    }

    protected function initComposer(string $url, ?string $username, ?string $password): ProxyOptions
    {
        $options = $this->createComposerProxyOptions($url, $username, $password);
        $http = $this->downloader->getHttpClient($options, $this->io);

        $response = $http->get($options->getUrl('packages.json'));
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('Unable to download packages.json from ' . $options->getUrl() . '. Error:' . $response->getBody());
        }

        $root = $response->decodeJson();
        return $options->withRoot($root);
    }

    protected function createComposerProxyOptions(string $url, ?string $username, ?string $password): ProxyOptions
    {
        return new ProxyOptions(['url' => $url, 'http_basic' => $username ? ['username' => $username, 'password' => $password] : null]);
    }

    protected function httpLoop(iterable $generator, HttpDownloader $http, callable $factory): array
    {
        $loop = new SignalLoop($http, null);

        $promises = $updated = $skipped = [];
        foreach ($generator as $key => $value) {
            if ($promise = $factory($key, $value, $http)) {
                $promises[] = $promise;
                $updated[$key] = $value;
            } else {
                $skipped[$key] = $value;
            }
        }

        $loop->wait($promises);

        return [$updated, $skipped];
    }
}
