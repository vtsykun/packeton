<?php

declare(strict_types=1);

namespace Packeton\Import;

use Composer\IO\IOInterface;
use Packeton\Entity\Job;
use Packeton\Entity\OAuthIntegration;
use Packeton\Form\Model\ImportRequest;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\Service\FetchPackageMetadataService;
use Packeton\Mirror\Service\ProxyHttpDownloader;
use Packeton\Service\JobScheduler;
use Packeton\Util\PacketonUtils;
use Symfony\Component\Finder\Glob;

class MassImportHandler
{
    protected ?IOInterface $io = null;

    public function __construct(
        protected ProxyHttpDownloader $downloader,
        protected FetchPackageMetadataService $metadataService,
        protected JobScheduler $jobScheduler,
    ) {
    }

    public function setIO(?IOInterface $io): void
    {
        $this->io = $io;
    }

    public function createImportJob(ImportRequest $request): Job
    {
        $repos = $this->getRepoUrls($request);

        return $this->jobScheduler->publish('mass:import', [
            'clone' => $request->clone,
            'integration' => $request->integration?->getId(),
            'type' => $request->type,
            'credentials' => $request->credentials?->getId(),
            'repos' => $repos,
        ]);
    }

    public function getRepoUrls(ImportRequest $request): array
    {
        $repos = match ($request->type) {
            'composer' => $this->fetchComposerRepos($request->composerUrl, $request->username, $request->password, $request->limit, $request->packageFilter),
            'vcs' => $this->getVCSRepos($request->repoList),
            'integration' => $this->fetchIntegrations($request->integration),
            default => [],
        };

        $filter = $this->toRegex($request->filter);
        foreach ($repos as $i => $repoUrl) {
            $parts = PacketonUtils::parserRepositoryUrl($repoUrl);
            if ($parts['namespace'] && $filter && !preg_match($filter, $parts['namespace'])) {
                unset($repos[$i]);
            }

            if ($request->type === 'composer' && $parts['hostname']) {
                $repos[$i] = match (true) {
                    $request->clone === 'ssh' && $parts['ssh_url'] => $parts['ssh_url'],
                    $request->clone === 'http' && $parts['http_url'] => $parts['http_url'],
                    default => $repoUrl,
                };
            }
        }

        return $repos;
    }

    protected function getVCSRepos(?string $list): array
    {
        if (!$list) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $list))));
    }

    protected function fetchIntegrations(?OAuthIntegration $integration): array
    {
        if (null === $integration) {
            return [];
        }


    }

    protected function fetchComposerRepos(string $url, string $username = null, string $password = null, int $limit = null, string $filter = null): array
    {
        $options = $this->createComposerProxyOptions($url, $username, $password);
        $http = $this->downloader->getHttpClient($options, $this->io);

        $filter = $this->toRegex($filter);
        $repository = new ImportComposerRepository($http, $options, $this->metadataService, $limit, $filter);

        $packages = $repository->getPackages();

        return array_keys($packages);
    }

    protected function createComposerProxyOptions(string $url, ?string $username, ?string $password): ProxyOptions
    {
        return new ProxyOptions(['url' => $url, 'http_basic' => $username ? ['username' => $username, 'password' => $password] : null]);
    }

    protected function toRegex(?string $filter): ?string
    {
        if (!$filter) {
            return null;
        }

        $filter = array_values(array_filter(array_map('trim', explode("\n", $filter))));
        $filter = array_map(fn($f) => Glob::toRegex($f, delimiter: ''), $filter);
        if (count($filter) === 1) {
            return '#' . $filter[0] . '#';
        }

        return '#(' . implode("|", $filter) . ')#';
    }
}
