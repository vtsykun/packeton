<?php

declare(strict_types=1);

namespace Packeton\Import;

use Composer\IO\IOInterface;
use Packeton\Entity\Job;
use Packeton\Entity\OAuthIntegration;
use Packeton\Entity\User;
use Packeton\Form\Model\ImportRequest;
use Packeton\Integrations\IntegrationRegistry;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\Service\FetchPackageMetadataService;
use Packeton\Mirror\Service\ProxyHttpDownloader;
use Packeton\Mirror\Utils\MirrorTextareaParser;
use Packeton\Service\JobScheduler;
use Packeton\Util\PacketonUtils;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Glob;
use Symfony\Component\Security\Core\User\UserInterface;

class MassImportHandler
{
    protected ?IOInterface $io = null;

    public function __construct(
        protected ProxyHttpDownloader $downloader,
        protected FetchPackageMetadataService $metadataService,
        protected JobScheduler $jobScheduler,
        protected MirrorTextareaParser $textareaParser,
        protected IntegrationRegistry $integrationRegistry,
        #[Autowire(param: 'packeton_max_import')] protected ?int $maxLimit = 10000,
    ) {
        $this->maxLimit ??= 10000;
    }

    public function setIO(?IOInterface $io): void
    {
        $this->io = $io;
    }

    public function createImportJob(ImportRequest $request, UserInterface $user = null): Job
    {
        $repos = $this->getRepoUrls($request);

        return $this->jobScheduler->publish('mass:import', [
            'clone' => $request->clone,
            'integration' => $request->integration?->getId(),
            'type' => $request->type,
            'credentials' => $request->credentials?->getId(),
            'repos' => $repos,
            'user' => $user instanceof User ? $user->getId() : null,
        ]);
    }

    public function getRepoUrls(ImportRequest $request): array
    {
        $repos = match ($request->type) {
            'composer' => $this->fetchComposerRepos($request->composerUrl, $request->username, $request->password, $request->limit, $request->packageList, $request->packageFilter),
            'vcs' => $this->getVCSRepos($request->repoList),
            'integration' => $this->fetchIntegrations($request->integration, (bool)$request->integrationInclude, $request->integrationRepos, $request->clone),
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

        $limit = (int) min($this->maxLimit, $request->limit ?? $this->maxLimit);
        if (count($repos) > $limit) {
            $repos = array_slice($repos, 0, $limit);
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

    protected function fetchIntegrations(?OAuthIntegration $integration, bool $isInclude, ?array $repoIds, ?string $clone): array
    {
        if (null === $integration) {
            return [];
        }

        $repoIds ??= [];
        $app = $this->integrationRegistry->findApp($integration->getAlias());
        $repos = $app->repositories($integration);

        $result = [];
        foreach ($repos as $repo) {
            if (
                ($isInclude && in_array($repo['ext_ref'], $repoIds, true))
                || (!$isInclude && !in_array($repo['ext_ref'], $repoIds, true))
            ) {
                $result[$repo['ext_ref']] = $clone === 'ssh' && ($repo['ssh_url']) ? $repo['ssh_url'] : $repo['url'];
            }
        }

        return $result;
    }

    protected function fetchComposerRepos(string $url, string $username = null, string $password = null, int $limit = null, string $packageList = null, string $filter = null): array
    {
        if (preg_match('{^(\.|[a-z]:|/)}i', $url)) {
            throw new \RuntimeException("local filesystem URLs is not allowed");
        }
        if (!str_starts_with($url, 'http')) {
            $url = 'https://' . $url;
        }

        $filter = $this->toRegex($filter);
        $packageNames = $this->textareaParser->parser($packageList);

        $options = $this->createComposerProxyOptions($url, $username, $password);
        $http = $this->downloader->getHttpClient($options, $this->io);

        $limit ??= $this->maxLimit;
        $limit = (int)min($limit, $this->maxLimit);

        $repository = new ImportComposerRepository($http, $options, $this->metadataService, $limit, $filter);
        $packages = $repository->getPackages($packageList ? $packageNames : null);

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
