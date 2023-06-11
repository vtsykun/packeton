<?php

declare(strict_types=1);

namespace Packeton\Integrations\Github;

use Composer\Config;
use Composer\IO\IOInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\OAuthIntegration as App;
use Packeton\Entity\User;
use Packeton\Integrations\AppInterface;
use Packeton\Integrations\Base\AppIntegrationTrait;
use Packeton\Integrations\Base\BaseIntegrationTrait;
use Packeton\Integrations\IntegrationInterface;
use Packeton\Integrations\LoginInterface;
use Packeton\Integrations\Model\AppUtils;
use Packeton\Integrations\Model\OAuth2State;
use Packeton\Service\Scheduler;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GitHubIntegration implements IntegrationInterface, LoginInterface, AppInterface
{
    use BaseIntegrationTrait;
    use AppIntegrationTrait;

    protected string $baseUrl = 'https://github.com';
    protected string $apiUrl = 'https://api.github.com';
    protected string $name;

    protected array $baseScores = ['user:email'];
    protected array $appScores = ['read:org', 'repo', 'admin:org_hook', 'admin:repo_hook'];
    protected ?string $authorizationResponseRoute = null;

    public function __construct(
        protected array $config,
        protected HttpClientInterface $httpClient,
        protected RouterInterface $router,
        protected OAuth2State $state,
        protected LockFactory $lock,
        protected ManagerRegistry $registry,
        protected Scheduler $scheduler,
        protected \Redis $redis,
        protected LoggerInterface $logger,
    ) {
        $this->name = $config['name'];
        if ($config['base_url'] ?? false) {
            $this->baseUrl = rtrim($config['base_url'], '/');
        }

        $this->init();
    }

    protected function init(): void
    {
        if (empty($this->config['default_roles'])) {
            $this->config['default_roles'] = ['ROLE_MAINTAINER', 'ROLE_GITHUB'];
        }

        $this->remoteContentUrl = '/repos/{project_id}/contents/{file}?ref={ref}';
    }

    /**
     * {@inheritdoc}
     */
    public function redirectOAuth2Url(Request $request = null, array $options = []): RedirectResponse
    {
        $options = $options + ['scope' => $this->baseScores];

        return $this->getAuthorizationResponse($this->baseUrl . '/login/oauth/authorize', $options, $this->authorizationResponseRoute);
    }

    /**
     * {@inheritdoc}
     */
    public function redirectOAuth2App(Request $request = null, array $options = []): RedirectResponse
    {
        $options = $options + ['scope' => $this->appScores];

        return $this->getAuthorizationResponse($this->baseUrl . '/login/oauth/authorize', $options, $this->authorizationResponseRoute ?: 'oauth_install');
    }

    /**
     * {@inheritdoc}
     */
    public function organizations(App $app, bool $withCache = true): array
    {
        $orgs = $this->getCached($app->getId(), 'orgs', $withCache, function () use ($app) {
            $accessToken = $this->refreshToken($app);
            $param = $this->getApiHeaders($accessToken);
            $response = $this->httpClient->request('GET', $this->getApiUrl('/user/memberships/orgs'), $param);
            return $response->toArray();
        });

        $orgs = array_map(function ($org) {
            $org['identifier'] = $org['organization']['login'];
            $org['name'] = $org['organization']['login'];
            $org['logo'] = $org['organization']['avatar_url'];

            return $org;
        }, $orgs);

        $own = [['name' => 'Own profile', 'identifier' => '@self']];

        return array_merge($own, $orgs);
    }

    /**
     * {@inheritdoc}
     */
    public function repositories(App $app): array
    {
        $orgs = $app->getEnabledOrganizations();
        $accessToken = null;

        $allRepos = [];
        $callback = function() use ($app, &$accessToken, &$url) {
            $accessToken ??= $this->refreshToken($app);
            return $this->makeCGetRequest($accessToken, $url);
        };

        if ($app->isConnected('@self')) {
            $url = '/user/repos';
            $userRepos = $this->getCached($app->getId(), 'repos:@self', callback: $callback);
            $allRepos = array_merge($allRepos, $userRepos);
        }

        foreach ($orgs as $org) {
            if ($org === '@self') {
                continue;
            }
            $url = '/orgs/'.rawurlencode($org).'/repos';
            try {
                $orgRepos = $this->getCached($app->getId(), "repos:$org", callback: $callback);
            } catch (\Exception $e) {
                $this->logger->error("Unable to get repos from organization $org. " . $e->getMessage());
                // If user does not have access
                continue;
            }

            $allRepos = array_merge($orgRepos, $allRepos);
        }

        return $this->formatRepos($allRepos);
    }

    /**
     * {@inheritdoc}
     */
    public function addOrgHook(App $app, int|string $orgId): ?array
    {
        return $this->doAddHook($app, (string) $orgId, false);
    }

    /**
     * {@inheritdoc}
     */
    public function removeOrgHook(App $app, int|string $orgId): ?array
    {
        return $this->doRemoveHook($app, (string) $orgId, false);
    }

    /**
     * {@inheritdoc}
     */
    public function addHook(App $app, int|string $repoId): ?array
    {
        if ($repoId = $this->findRepoNameById($app, $repoId)) {
            return $this->doAddHook($app, $repoId, true);
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function removeHook(App $app, int|string $repoId, array $webHookInfo = null): ?array
    {
        if (isset($webHookInfo['owner_id'], $webHookInfo['id'])) {
            return $this->doRemoveHook($app, $webHookInfo['owner_id'], true, $webHookInfo['id']);
        }

        if ($repoId = $this->findRepoNameById($app, $repoId)) {
            return $this->doRemoveHook($app, $repoId, true);
        }

        return null;
    }

    protected function findRepoNameById(App $app, $extRef): ?string
    {
        $repos = $this->repositories($app);
        $repos = array_filter($repos, fn ($repo) => $repo['ext_ref'] === $extRef);
        $repos = reset($repos) ?: null;
        return $repos['full_name'] ?? null;
    }

    public function findHooks(App|array $accessToken, int|string $orgId, ?bool $isRepo = null, ?string $url = null): ?array
    {
        $orgId = (string) $orgId;
        $isRepo ??= count(explode('/', $orgId)) > 1;

        $accessToken = $this->refreshToken($accessToken);
        try {
            $list = $this->makeCGetRequest($accessToken, $isRepo ? "/repos/$orgId/hooks" : "/orgs/$orgId/hooks");
        } catch (\Exception $e) {
            return $url ? null : [];
        }

        if (null !== $url) {
            $list = array_filter($list, fn ($u) => $u['config']['url'] === $url);
            return reset($list) ?: null;
        }

        return $list;
    }

    protected function doAddHook(App $app, string $orgId, bool $isRepo): ?array
    {
        if ('@self' === $orgId) {
            return null;
        }

        $url = $this->getConfig($app)->getHookUrl();
        $body = ['name' => 'web', 'config' => ['url' => $url, 'content_type' => 'json'], 'events' => ['push', 'pull_request']];
        $accessToken = $this->refreshToken($app);
        if ($hook = $this->findHooks($accessToken, $orgId, $isRepo, $url)) {
            return ['status' => true, 'id' => $hook['id']];
        }

        try {
            $response = $this->makeApiRequest($accessToken, 'POST', $isRepo ? "/repos/$orgId/hooks" : "/orgs/$orgId/hooks", ['json' => $body]);
            if (isset($response['id'])) {
                return ['status' => true, 'id' => $response['id'], 'owner_id' => $orgId];
            }
        } catch (\Exception $e) {
            return ['status' => false, 'error' => AppUtils::castError($e), 'status_message' => $statusMessage ?? null];
        }

        if ($hook = $this->findHooks($accessToken, $orgId, $isRepo, $url)) {
            return ['status' => true, 'id' => $hook['id'], 'owner_id' => $orgId];
        }
        return null;
    }

    protected function doRemoveHook(App $app, string $orgId, bool $isRepo, int $hookId = null): ?array
    {
        if ('@self' === $orgId) {
            return null;
        }

        $accessToken = $this->refreshToken($app);
        $url = $this->getConfig($app)->getHookUrl();

        $id = false === $isRepo ? ($app->getWebhookInfo($orgId)['id'] ?? $hookId) : $hookId;
        if ($id !== null) {
            try {
                $this->makeApiRequest($accessToken, 'DELETE', $isRepo ? "/repos/$orgId/hooks/$id" : "/orgs/$orgId/hooks/$id");
                return [];
            } catch (\Exception $e) {
            }
        }

        if ($hook = $this->findHooks($accessToken, $orgId, $isRepo, $url)) {
            $id = $hook['id'];
            try {
                $this->makeApiRequest($accessToken, 'DELETE', $isRepo ? "/repos/$orgId/hooks/$id" : "/orgs/$orgId/hooks/$id");
                return [];
            } catch (\Exception $e) {
                return ['status' => false, 'error' => AppUtils::castError($e), 'id' => $id];
            }
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function receiveHooks(App $accessToken, Request $request = null, ?array $payload = null): ?array
    {
        if (null === $payload || !($repoName = $payload['repository']['full_name'] ?? null)) {
            return null;
        }

        $repoId = $payload['repository']['id'];
        $kind = $request->headers->get('x-github-event', 'push');

        if (in_array($kind, ['push', 'tag_push'])) {
            return $this->processPushEvent($accessToken, $payload, $repoId, $repoName);
        }

        if ($kind === 'pull_request') {
            return $this->processPullRequest($accessToken, $payload, $repoName);
        }

        return null;
    }

    protected function processPullRequest(App $app, array $payload, $repoName): ?array
    {
        $statues = ['opened', 'synchronize', 'reopened'];
        if (!in_array($payload['action'] ?? 'opened', $statues)) {
            return null;
        }

        $pullRequest = $payload['pull_request'];

        $data = [
            'id' => $repoName,
            'name' => $repoName,
            'source_id' => $pullRequest['head']['repo']['full_name'],
            'target_id' => $pullRequest['base']['repo']['full_name'],
            'source_branch' => $pullRequest['head']['ref'],
            'target_branch' => $pullRequest['base']['ref'],
            'iid' => $iid = $pullRequest['number'],
        ];

        return $this->pullRequestReview($app, $data, function (string $method, string $diff, $commentId = null) use ($app, $repoName, $iid) {
            $token = $this->refreshToken($app);
            $body = ['body' => $diff];
            if ($method === 'PUT') {
                return $this->makeApiRequest($token, "PATCH", "/repos/$repoName/issues/comments/$commentId", ['json' => $body]);
            }
            if ($method === 'POST') {
                return $this->makeApiRequest($token, "POST", "/repos/$repoName/issues/$iid/comments", ['json' => $body]);
            }
            return [];
        });
    }

    protected function processPushEvent(App $app, array $payload, $id, $repoName): ?array
    {
        $data = [
            'id' => $repoName,
            'name' => $repoName,
            'external_ref' => "{$this->name}:$id",
            'urls' => [
                $payload['repository']['git_url'] ?? null,
                $payload['repository']['ssh_url'] ?? null,
                $payload['repository']['clone_url'] ?? null,
                $payload['repository']['url'] ?? null,
            ]
        ];

        return $this->pushEventSynchronize($app, $data + $payload);
    }

    protected function getRemoteContent(array $token, string|int $projectId, string $file, ?string $ref = null, bool $asJson = true): null|string|array
    {
        $url = str_replace(['{project_id}', '{file}', '{ref}'], [(string)$projectId, $file, $ref], $this->remoteContentUrl);
        if (empty($ref)) {
            $url = str_replace('?ref=', '', $url);
        }

        try {
            $content = $this->makeApiRequest($token, 'GET', $url, ['headers' => ['Accept' => 'application/vnd.github.raw']], false);
        } catch (\Throwable $e) {
            return null;
        }

        $content = $asJson && is_string($content) ? json_decode($content, true) : $content;

        return $asJson ? (is_array($content) ? $content : null) : $content;
    }

    protected function formatRepos(array $repos): array
    {
        return array_map(function ($repo) {
            $required = [
                'name' => $repo['full_name'],
                'label' => $repo['full_name'],
                'ext_ref' => $this->name.':'.$repo['id'],
                'url' => $repo['clone_url'],
                'ssh_url' => $repo['ssh_url'] ?? null,
            ];

            return $required + $repo;
        }, $repos);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(Request $request, array $options = []): array
    {
        if (!$code = $request->get('code')) {
            throw new BadRequestHttpException('No "code" parameter was found (usually this is a query parameter)!');
        }

        if (!$this->checkState($request->query->get('state'))) {
            throw new BadRequestHttpException('No "state" parameter is not a valid');
        }

        $query = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code'  => $code,
            'grant_type' => 'authorization_code'
        ];

        $params = [
            'query' => $query,
            'headers' => ['Accept' => 'application/json']
        ];

        $response = $this->httpClient->request('POST', $this->baseUrl . '/login/oauth/access_token', $params);
        return $response->toArray() + ['created_in' => time()];
    }

    /**
     * {@inheritdoc}
     */
    public function fetchUser(Request|array $requestOrToken, array $options = [], array &$accessToken = null): array
    {
        $accessToken ??= $requestOrToken instanceof Request ? $this->getAccessToken($requestOrToken) : $requestOrToken;

        $params = $this->getAuthorizationHeaders($accessToken);

        $response = $this->httpClient->request('GET', $this->getApiUrl('/user'), $params);
        $response = $response->toArray();

        $response['user_name'] = $response['login'] ?? null;
        $response['user_identifier'] = $response['email'];
        $response['external_id'] = isset($response['id']) ? $this->getConfig()->getName() . ':' . $response['id'] : null;

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function authenticateIO(App $oauth2, IOInterface $io, Config $config, string $repoUrl = null): void
    {
        $token = $this->refreshToken($oauth2);
        $urls = parse_url($this->baseUrl);

        $params = [
            '_driver' => 'github',
            '_no_api' => !AppUtils::useApiPref($this->getConfig(), $oauth2),
            'github-domains' => ['github.com', $urls['host']],
            'github-oauth' => [$urls['host'] => $token['access_token']],
        ];

        $params += $this->config['composer_config'] ?? [];
        $config->merge(['config' => $params]);
        $io->loadConfiguration($config);
    }

    protected function doRefreshToken(array $token): array
    {
        if (!isset($token['refresh_token'])) {
            return $token;
        }

        $query = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'grant_type'  => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
        ];
        $params = [
            'query' => $query,
            'headers' => ['Accept' => 'application/json']
        ];

        $response = $this->httpClient->request('POST', $this->baseUrl . '/login/oauth/access_token', $params);
        $newToken = $response->toArray();

        $newToken = array_merge($token, $newToken);
        $newToken['created_in'] = time();

        return $newToken;
    }

    protected function isTokenExpired(array $token): bool
    {
        if (!isset($token['expires_in'], $token['refresh_token'])) {
            return false;
        }

        $expireAt = ($token['created_in'] ?? 0) + $token['expires_in'];
        return $expireAt < time() + 60;
    }

    protected function getApiUrl(string $path): string
    {
        return $this->baseUrl === 'https://github.com' ? $this->apiUrl . $path : $this->baseUrl . '/api/v3' . $path;
    }

    protected function getAuthorizationHeaders(array $token): array
    {
        return array_merge_recursive($this->config['http_options'] ?? [], [
            'headers' => [
                'Authorization' => "Bearer {$token['access_token']}",
            ]
        ]);
    }

    protected function getApiHeaders(array $token, array $default = []): array
    {
        $params = $this->getAuthorizationHeaders($token);
        $params['headers'] = array_merge($params['headers'], ['Accept' => 'application/vnd.github+json'], $default);
        return $params;
    }

    /**
     * {@inheritdoc}
     */
    public function createUser(array $userData): User
    {
        $user = new User();
        $user->setEnabled(true)
            ->setRoles($this->getConfig()->roles())
            ->setEmail($userData['email'])
            ->setUsername($userData['login'])
            ->setGithubId($userData['external_id'] ?? null)
            ->generateApiToken();

        return $user;
    }
}
