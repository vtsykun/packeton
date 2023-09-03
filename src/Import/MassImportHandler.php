<?php

declare(strict_types=1);

namespace Packeton\Import;

use Composer\IO\IOInterface;
use Packeton\Form\Model\ImportRequest;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\Service\FetchPackageMetadataService;
use Packeton\Mirror\Service\ProxyHttpDownloader;
use Symfony\Component\Finder\Glob;

class MassImportHandler
{
    protected ?IOInterface $io = null;

    public function __construct(
        protected ProxyHttpDownloader $downloader,
        protected FetchPackageMetadataService $metadataService
    ) {
    }

    public function setIO(?IOInterface $io): void
    {
        $this->io = $io;
    }

    public function getRepoUrls(ImportRequest $request): array
    {
        $repos = match ($request->type) {
            'composer' => $this->fetchComposerRepos($request->composerUrl, $request->username, $request->password, $request->limit, $request->packageFilter),
        };

        return [];
    }

    protected function fetchComposerRepos(string $url, string $username = null, string $password = null, int $limit = null, string $filter = null): array
    {
        $options = $this->createComposerProxyOptions($url, $username, $password);
        $http = $this->downloader->getHttpClient($options, $this->io);

        $filter = $filter ? Glob::toRegex($filter) : null;
        $repository = new ImportComposerRepository($http, $options, $this->metadataService, $limit, $filter);

        $packages = $repository->getPackages();

        return array_keys($packages);
    }

    protected function createComposerProxyOptions(string $url, ?string $username, ?string $password): ProxyOptions
    {
        return new ProxyOptions(['url' => $url, 'http_basic' => $username ? ['username' => $username, 'password' => $password] : null]);
    }
}
