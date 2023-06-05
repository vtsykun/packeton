<?php

declare(strict_types=1);

namespace Packeton\Integrations\Gitlab;

use Composer\Config;
use Composer\IO\IOInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\OAuthIntegration;
use Packeton\Entity\OAuthIntegration as App;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Integrations\AppInterface;
use Packeton\Integrations\Base\AppIntegrationTrait;
use Packeton\Integrations\Base\BaseIntegrationTrait;
use Packeton\Integrations\Github\GithubResultPager;
use Packeton\Integrations\IntegrationInterface;
use Packeton\Integrations\LoginInterface;
use Packeton\Integrations\Model\AppUtils;
use Packeton\Integrations\Model\OAuth2State;
use Packeton\Service\Scheduler;
use Psr\Cache\CacheItemInterface as CacheItem;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface as UG;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GitLabIntegration implements IntegrationInterface, LoginInterface, AppInterface
{
    use BaseIntegrationTrait;
    use AppIntegrationTrait;
    protected const GITLAB_HOST = 'https://gitlab.com';

    protected $pathAuthorize = '/oauth/authorize';
    protected $pathToken = '/oauth/token';
    protected $separator = ' ';

    protected $baseUrl = 'https://gitlab.com';
    protected $name;

    public function __construct(
        protected array $config,
        protected HttpClientInterface $httpClient,
        protected RouterInterface $router,
        protected OAuth2State $state,
        protected Scheduler $scheduler,
        protected LockFactory $lock,
        protected ManagerRegistry $registry,
        protected \Redis $redis,
        protected LoggerInterface $logger,
    ) {
        $this->name = $config['name'];
        if (empty($this->config['oauth2_registration']['default_roles'])) {
            $this->config['oauth2_registration']['default_roles'] = ['ROLE_MAINTAINER', 'ROLE_USER'];
        }
        $this->config['oauth2_registration']['default_roles'][] = 'ROLE_GITLAB';

        if ($config['base_url'] ?? false) {
            $this->baseUrl = rtrim($config['base_url'], '/');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function redirectOAuth2Url(Request $request = null, array $options = []): Response
    {
        $options = $options + ['scope' => ['read_user']];
        return $this->getAuthorizationResponse($this->baseUrl . $this->pathAuthorize, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function redirectOAuth2App(Request $request = null, array $options = []): Response
    {
        $options = $options + ['scope' => ['read_user', 'api']];
        return $this->getAuthorizationResponse($this->baseUrl . $this->pathAuthorize, $options, 'oauth_install');
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(Request $request, array $options = []): array
    {
        if (!$request->get('code') || !$this->checkState($request->get('state'))) {
            throw new BadRequestHttpException('No "code" and "state" parameter was found (usually this is a query parameter)!');
        }

        $route = $this->state->getStateBag()->get('route');
        $redirectUrl = $this->router->generate($route, ['alias' => $this->name], UG::ABSOLUTE_URL);

        $query = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code'  => $request->get('code'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUrl,
        ];

        $response = $this->httpClient->request('POST', $this->baseUrl . $this->pathToken, ['query' => $query]);

        return $response->toArray() + ['redirect_uri' => $redirectUrl];
    }

    /**
     * {@inheritdoc}
     */
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
            'redirect_uri' => $token['redirect_uri']
        ];

        $response = $this->httpClient->request('POST', $this->baseUrl . $this->pathToken, ['query' => $query]);
        $newToken = $response->toArray();

        return array_merge($token, $newToken);
    }

    /**
     * {@inheritdoc}
     */
    protected function isTokenExpired(array $token): bool
    {
        if (!isset($token['expires_in'], $token['refresh_token'], $token['created_at'])) {
            return false;
        }

        $expireAt = $token['created_at'] + $token['expires_in'];
        return $expireAt < time() + 65;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchUser(Request|array $request, array $options = [], array &$accessToken = null): array
    {
        $accessToken ??= $request instanceof Request ? $this->getAccessToken($request) : $request;

        $response = $this->makeApiRequest($accessToken, 'GET', '/user');

        $response['user_name'] = $response['username'] ?? null;
        $response['user_identifier'] = $response['email'];
        $response['external_id'] = isset($response['id']) ? "{$this->name}:{$response['id']}" : null;

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function createUser(array $data): User
    {
        $user = new User();
        $user->setEnabled(true)
            ->setRoles($this->getConfig()->roles())
            ->setEmail($data['email'])
            ->setUsername($data['username'] ?? ($this->name.$data['id']))
            ->setGithubId($data['external_id'] ?? null)
            ->generateApiToken();

        return $user;
    }

    /**
     * {@inheritdoc}
     */
    public function organizations(App $app): array
    {
        $isGitLab = $this->baseUrl === self::GITLAB_HOST;
        $organizations = $this->getCached($app, 'orgs', callback: function () use ($app) {
            $accessToken = $this->refreshToken($app);
            return $this->makeCGetRequest($accessToken, '/groups', ['query' => ['min_access_level' => 30]]);
        });

        $orgs = array_map(fn ($org) => $org + ['identifier' => $org['id'], 'logo' => $isGitLab ? null : $org['avatar_url'] ?? null, 'name' => $org['name'] ?? $org['id']], $organizations);
        return array_merge([$this->ownOrg], $orgs);
    }

    /**
     * {@inheritdoc}
     */
    public function repositories(App $app): array
    {
        $organizations = $app->getEnabledOrganizations();
        $organizations = array_diff($organizations, ['@self']);

        $allRepos = [];
        if ($app->isConnected('@self')) {
            $ownRepos = $this->getCached($app, 'repos:self', callback: function () use ($app) {
                $accessToken = $this->refreshToken($app);
                return $this->makeCGetRequest($accessToken, '/projects', ['query' => ['membership' => true, 'owned' => true]]);
            });

            $allRepos = array_merge($allRepos, $ownRepos);
        }

        foreach ($organizations as $organization) {
            $orgRepos = $this->getCached($app, "repos:$organization", callback: function () use ($app, $organization) {
                $accessToken = $this->refreshToken($app);

                try {
                    return $this->makeCGetRequest($accessToken, "/groups/{$organization}/projects", ['query' => ['membership' => true, 'owned' => true]]);
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage(), ['e' => $e]);
                    return [];
                }
            });

            $allRepos = array_merge($allRepos, $orgRepos);
        }

        return $this->formatRepos($allRepos);
    }

    protected function formatRepos(array $repos): array
    {
        $repos = array_map(function ($repo) {
            $required = [
                'name' => $repo['path_with_namespace'],
                'label' => $repo['path_with_namespace'],
                'ext_ref' => $this->name.':'.$repo['id'],
                'url' => $repo['http_url_to_repo'] ?? $repo['web_url'],
                'ssh_url' => $repo['ssh_url_to_repo'] ?? null,
            ];

            return $required + $repo;
        }, $repos);

        $unique = [];
        foreach ($repos as $repo) {
            $unique[$repo['name']] = $repo;
        }

        return array_values($unique);
    }

    /**
     * {@inheritdoc}
     */
    public function getRedirectUrls(): array
    {
        return [
            $this->router->generate('oauth_install', ['alias' => $this->name], UG::ABSOLUTE_URL),
            $this->router->generate('oauth_check', ['alias' => $this->name], UG::ABSOLUTE_URL),
        ];
    }

    public function findHooks(App|array $accessToken, int|string $orgId, ?bool $isRepo = null, ?string $url = null): ?array
    {
        $orgId = str_replace($this->name.':', '', (string)$orgId, $count);
        $isRepo ??= $count > 0;

        $accessToken = $this->refreshToken($accessToken);
        try {
            $list = $this->makeCGetRequest($accessToken, $isRepo ? "/projects/$orgId/hooks" : "/groups/$orgId/hooks");
        } catch (\Exception $e) {
            return $url ? null : [];
        }

        if (null !== $url) {
            $list = array_filter($list, fn ($u) => $u['url'] === $url);
            return reset($list) ?: null;
        }

        return $list;
    }

    /**
     * {@inheritdoc}
     */
    public function addOrgHook(App $app, int|string $orgId): ?array
    {
        return $this->doAddHook($app, $orgId, false);
    }

    /**
     * {@inheritdoc}
     */
    public function removeOrgHook(App $app, int|string $orgId): ?array
    {
        return $this->doRemoveHook($app, $orgId, false);
    }

    /**
     * {@inheritdoc}
     */
    public function addHook(App $app, int|string $orgId): ?array
    {
        return $this->doAddHook($app, $orgId, true);
    }

    /**
     * {@inheritdoc}
     */
    public function removeHook(App $app, int|string $orgId): ?array
    {
        return $this->doRemoveHook($app, $orgId, true);
    }

    /**
     * {@inheritdoc}
     */
    public function authenticateIO(OAuthIntegration $oauth2, IOInterface $io, Config $config, string $repoUrl = null): void
    {
        $token = $this->refreshToken($oauth2);
        $urls = parse_url($this->baseUrl);

        $params = [
            '_driver' => 'gitlab',
            '_no_api' => !($useApi = AppUtils::useApiPref($this->getConfig(), $oauth2)),
            'gitlab-domains' => ['gitlab.com', $urls['host']],
            'gitlab-oauth' => [$urls['host'] => $token['access_token']],
        ];

        if (false === $useApi) {
            $params['_driver'] = 'git';
        }

        $params += $this->config['composer_config'] ?? [];
        $config->merge(['config' => $params]);
        $io->loadConfiguration($config);
    }

    protected function doAddHook(App $app, int|string $orgId, bool $isRepo): ?array
    {
        if ('@self' === $orgId) {
            return null;
        }

        $orgId = str_replace($this->name.':', '', (string)$orgId);
        $accessToken = $this->refreshToken($app);
        $url = $this->getConfig($app)->getHookUrl();
        if ($hook = $this->findHooks($accessToken, $orgId, $isRepo, $url)) {
            return ['status' => true, 'id' => $hook['id']];
        }

        try {
            $body = ['url' => $url, 'push_events' => true, 'tag_push_events' => true, 'merge_requests_events' => true];
            $response = $this->makeApiRequest($accessToken, 'POST', $isRepo ? "/projects/$orgId/hooks" : "/groups/$orgId/hooks", ['json' => $body]);
            if (isset($response['id'])) {
                return ['status' => true, 'id' => $response['id']];
            }
        } catch (\Exception $e) {
            if ($e instanceof HttpExceptionInterface && $e->getResponse()->getStatusCode() === 404 && false === $isRepo) {
                $statusMessage = 'Notice. GitLab allow Groups webhooks only for EE paid plan. But you may manually setup '
                    . "Packagist integration with target on packeton host (without path), username \"token\" and token \"{$app->getHookToken()}\". "
                    . "See documentation for details";
            }

            return ['status' => false, 'error' => AppUtils::castError($e), 'status_message' => $statusMessage ?? null];
        }

        if ($hook = $this->findHooks($accessToken, $orgId, $isRepo, $url)) {
            return ['status' => true, 'id' => $hook['id']];
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    protected function doRemoveHook(App $app, int|string $orgId, bool $isRepo): ?array
    {
        if ('@self' === $orgId) {
            return null;
        }

        $accessToken = $this->refreshToken($app);
        $url = $this->getConfig($app)->getHookUrl();

        $id = false === $isRepo ? ($app->getWebhookInfo($orgId)['id'] ?? null) : null;
        if ($id !== null) {
            try {
                $this->makeApiRequest($accessToken, 'DELETE', $isRepo ? "/projects/$orgId/hooks/$id" : "/groups/$orgId/hooks/$id");
                return [];
            } catch (\Exception $e) {
            }
        }

        if ($hook = $this->findHooks($accessToken, $orgId, $isRepo, $url)) {
            $id = $hook['id'];
            try {
                $this->makeApiRequest($accessToken, 'DELETE', $isRepo ? "/projects/$orgId/hooks/$id" : "/groups/$orgId/hooks/$id");
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
        if (null === $payload || !($id = $payload['project']['id'] ?? null)) {
            return null;
        }

        $kind = $payload['object_kind'] ?? 'push';
        if (in_array($kind, ['push', 'tag_push'])) {
            return $this->processPushEvent($accessToken, $payload, $id);
        }

        return null;
    }

    protected function processPushEvent(App $app, array $payload, $id): ?array
    {
        $repo = $this->registry->getRepository(Package::class);
        $pkg = $repo->findOneBy(['externalRef' => $externalId = "{$this->name}:$id"]);
        if (null !== $pkg) {
            $pkg->setAutoUpdated(true);
            $job = $this->scheduler->scheduleUpdate($pkg);
            return ['status' => 'success', 'job' => $job->getId(), 'code' => 202];
        }

        $config = $this->getConfig();
        if (!AppUtils::enableSync($config, $app)
            || !($path = $payload['project']['path_with_namespace'] ?? null)
            || AppUtils::isRepoExcluded($app, $path, $this->organizations($app), 'full_path')
        ) {
            return null;
        }

        $hasNewComposer = $this->getCached($app, "hasComposer:$path", callback: function (CacheItem $item) use ($app, $id, $payload, $repo) {
            $item->expiresAfter(7*86400);
            $token = $this->refreshToken($app);
            try {
                $ref = $payload['project']['default_branch'];
                $data = $this->makeApiRequest($token, 'GET', "/projects/$id/repository/files/composer.json?ref=$ref");
                $content = base64_decode($data['content'] ?? '');
                $content = $content ? json_decode($content, true) : $content;
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage(), ['e' => $e]);
                return false;
            }

            if (!is_string($name = ($content['name'] ?? null))) {
                return false;
            }

            $pkg = $repo->findOneByName($name);
            return $pkg === null;
        });

        $newAdded = false;
        $commits = $payload['commits'] ?? [];
        foreach ($commits as $commit) {
            $files = array_merge((array)($commit['added'] ?? []), (array)($commit['modified'] ?? []));
            if (in_array('composer.json', $files)) {
                $newAdded = true;
                break;
            }
        }

        if (false === $newAdded && false === $hasNewComposer) {
            return null;
        }

        return $this->getCached($app, "sync:$path", false === $newAdded, function (CacheItem $item) use ($externalId, $app) {
            $item->expiresAfter(86400);
            $job = $this->scheduler->publish('integration:repo:sync', ['external_id' => $externalId, 'app' => $app->getId()], $app->getId());
            return ['status' => 'new_repo', 'job' => $job->getId(), 'code' => 202];
        });
    }

    protected function getApiUrl(string $path): string
    {
        $apiVer = $this->config['api_version'] ?? 'v4';
        return $this->baseUrl . '/api/' . $apiVer . $path;
    }

    protected function makeApiRequest(array $token, string $method, string $path, array $params = []): array
    {
        $params = array_merge_recursive($this->getApiHeaders($token), $params);
        $response = $this->httpClient->request($method, $this->getApiUrl($path), $params);
        $content = $response->getContent();
        $content = $content ? json_decode($content, true) : [];

        return is_array($content) ? $content : [];
    }

    protected function makeCGetRequest(array $token, string $path, array $params = []): array
    {
        $params = array_merge_recursive($this->getApiHeaders($token), $params);
        $paginator = new GithubResultPager($this->httpClient, $this->getApiUrl($path), $params);
        return $paginator->all();
    }

    protected function getApiHeaders(array $token, array $default = []): array
    {
        $params = array_merge_recursive($this->config['http_options'] ?? [], [
            'headers' => [
                'Authorization' => "Bearer {$token['access_token']}",
            ]
        ]);

        $params['headers'] = array_merge($params['headers'], ['Accept' => 'application/json'], $default);
        return $params;
    }
}
