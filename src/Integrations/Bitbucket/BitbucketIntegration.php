<?php

declare(strict_types=1);

namespace Packeton\Integrations\Bitbucket;

use Composer\Config;
use Composer\IO\IOInterface;
use Doctrine\Persistence\ManagerRegistry;
use Okvpn\Expression\TwigLanguage;
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface as UG;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BitbucketIntegration implements IntegrationInterface, LoginInterface, AppInterface
{
    use BaseIntegrationTrait;
    use AppIntegrationTrait;

    protected string $baseUrl = 'https://bitbucket.org';
    protected string $apiUrl = 'https://api.bitbucket.org';
    protected string $pathAuthorize = '/site/oauth2/authorize';
    protected string $pathToken = '/site/oauth2/access_token';

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
        protected TwigLanguage $twigLanguage,
        protected LoggerInterface $logger,
    ) {
        $this->name = $config['name'];
        if (empty($this->config['default_roles'])) {
            $this->config['default_roles'] = ['ROLE_MAINTAINER', 'ROLE_BITBUCKET'];
        }

        if ($config['base_url'] ?? false) {
            $this->baseUrl = rtrim($config['base_url'], '/');
        }

        $this->config['client_id'] = $config['key'];
        $this->config['client_secret'] = $config['secret'];

        $this->remoteContentUrl = "/repositories/{project_id}/src/{ref}/{file}";
        $this->remoteContentFormat = 'raw';
    }

    /**
     * {@inheritdoc}
     */
    public function getRedirectUrls(): array
    {
        return [
            $this->router->generate('oauth_auto_redirect', ['alias' => $this->name], UG::ABSOLUTE_URL),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function redirectOAuth2Url(Request $request = null, array $options = []): Response
    {
        $options['redirect_uri'] = false;
        $options['scope'] = ['email'];
        return $this->getAuthorizationResponse($this->baseUrl . $this->pathAuthorize, $options, 'oauth_auto_redirect');
    }

    /**
     * {@inheritdoc}
     */
    public function redirectOAuth2App(Request $request = null, array $options = []): Response
    {
        $options['redirect_uri'] = false;
        return $this->getAuthorizationResponse($this->baseUrl . $this->pathAuthorize, $options, 'oauth_auto_redirect');
    }

    /**
     * {@inheritdoc}
     */
    public function repositories(App $app): array
    {
        $organizations = $app->getEnabledOrganizations();
        $allRepos = [];
        foreach ($organizations as $organization) {
            $orgRepos = $this->getCached($app, "repos:$organization", callback: function () use ($app, $organization) {
                $accessToken = $this->refreshToken($app);
                try {
                    return $this->makeCGetRequest($accessToken, "/repositories/$organization");
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
        return array_map(function ($repo) {
            $links = $repo['links'];
            $sshLink = null;
            foreach ($links['clone'] ?? [] as $link) {
                if (isset($link['href'], $link['name']) && $link['name'] === 'ssh') {
                    $sshLink = $link['href'];
                }
            }

            $required = [
                'name' => $repo['full_name'],
                'label' => $repo['full_name'],
                'ext_ref' => $this->name.':'.$repo['uuid'],
                'url' => $links['html']['href'] . '.git',
                'ssh_url' => $sshLink,
            ];

            return $required + $repo;
        }, $repos);
    }

    /**
     * {@inheritdoc}
     */
    public function organizations(App $app): array
    {
        $organizations = $this->getCached($app, 'orgs', callback: function () use ($app) {
            $accessToken = $this->refreshToken($app);
            return $this->makeCGetRequest($accessToken, '/workspaces');
        });

        return $this->processOrganizations($organizations);
    }

    protected function processOrganizations(array $organizations): array
    {
        return array_map(function ($org) {
            $org['logo'] = $org['links']['avatar']['href'] ?? null;
            $org['identifier'] = $org['slug'];
            return $org;
        }, $organizations);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(Request $request, array $options = []): array
    {
        if (!$request->get('code') || !$this->checkState($request->get('state'))) {
            throw new BadRequestHttpException('No "code" and "state" parameter was found (usually this is a query parameter)!');
        }

        $query = [
            'code'  => $request->get('code'),
            'grant_type' => 'authorization_code',
        ];

        $param['body'] = $query;
        $param['auth_basic'] = [$this->config['client_id'], $this->config['client_secret']];

        $response = $this->httpClient->request('POST', $this->baseUrl . $this->pathToken, $param);
        return $response->toArray() + ['created_at' => time(), 'expires_in' => 3600];
    }

    /**
     * {@inheritdoc}
     */
    protected function isTokenExpired(array $token): bool
    {
        if (!isset($token['expires_in'], $token['refresh_token'])) {
            return false;
        }

        $expireAt = ($token['created_at'] ?? 0) + $token['expires_in'];
        return $expireAt < time() + 70;
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
            'grant_type'  => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
        ];
        $params = [
            'body' => $query,
            'auth_basic' => [$this->config['client_id'], $this->config['client_secret']],
        ];

        $response = $this->httpClient->request('POST', $this->baseUrl . $this->pathToken, $params);
        $newToken = $response->toArray();

        $newToken = array_merge($token, $newToken);
        $newToken['created_at'] = time();

        return $newToken;
    }

    /**
     * {@inheritdoc}
     */
    public function addOrgHook(App $accessToken, int|string $orgId): ?array
    {
        return $this->doAddHook($accessToken, (string)$orgId, false);
    }

    protected function doAddHook(App $app, string $orgId, bool $isRepo): ?array
    {
        $apiEndpoint = $isRepo ? "/repositories/$orgId/hooks" : "/workspaces/$orgId/hooks";
        $url = $this->getConfig($app)->getHookUrl();

        $body = [
            'description' => 'Packeton Hooks',
            'url' => $url,
            'active' => true,
            'events' => ['repo:push', 'pullrequest:created', 'pullrequest:updated'],
        ];

        $accessToken = $this->refreshToken($app);
        if ($hook = $this->findHooks($accessToken, $orgId, $isRepo, $url)) {
            return ['status' => true, 'id' => $hook['uuid']];
        }

        try {
            $response = $this->makeApiRequest($accessToken, 'POST', $apiEndpoint, ['json' => $body]);
            if (isset($response['uuid'])) {
                return ['status' => true, 'id' => $response['uuid'], 'owner_id' => $orgId];
            }
        } catch (\Exception $e) {
            return ['status' => false, 'error' => AppUtils::castError($e, $app), 'status_message' => $statusMessage ?? null];
        }

        if ($hook = $this->findHooks($accessToken, $orgId, $isRepo, $url)) {
            return ['status' => true, 'id' => $hook['uuid'], 'owner_id' => $orgId];
        }

        return null;
    }

    protected function findHooks(App|array $accessToken, string $orgId, ?bool $isRepo = null, ?string $url = null): ?array
    {
        $isRepo ??= count(explode('/', $orgId)) > 1;
        $accessToken = $this->refreshToken($accessToken);
        try {
            $list = $this->makeCGetRequest($accessToken, $isRepo ? "/repositories/$orgId/hooks" : "/workspaces/$orgId/hooks");
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
    public function removeOrgHook(App $accessToken, int|string $orgId): ?array
    {
        return $this->doRemoveHook($accessToken, (string)$orgId, false);
    }

    protected function doRemoveHook(App $app, string $orgId, bool $isRepo): ?array
    {
        $apiEndpoint = $isRepo ? "/repositories/$orgId/hooks/" : "/workspaces/$orgId/hooks/";
        $accessToken = $this->refreshToken($app);

        $url = $this->getConfig($app)->getHookUrl();
        if (!$hook = $this->findHooks($accessToken, $orgId, $isRepo, $url)) {
            return [];
        }

        try {
            $this->makeApiRequest($accessToken, 'DELETE', $apiEndpoint . $hook['id']);
            return [];
        } catch (\Exception $e) {
            return ['status' => false, 'error' => AppUtils::castError($e, $app), 'id' => $hook['id'] ?? null];
        }
    }

    protected function resolveRepoUUID(App $app, string $repoId): ?string
    {
        if (str_starts_with($repoId, $this->name .':')) {
            $repos = $this->repositories($app);
            $repo = array_filter($repos, fn ($r) => $r['ext_ref'] === $repoId);
            $repo = $repo ? reset($repo) : [];
            return $repo['name'] ?? null;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function addHook(App $app, int|string $repoId): ?array
    {
        if (!$repoId = $this->resolveRepoUUID($app, (string)$repoId)) {
            return null;
        }

        if (count($slugs = explode("/", $repoId)) >= 2) {
            $workspaces = $slugs[0];
            $url = $this->getConfig($app)->getHookUrl();
            // Skip if already exists organization hooks
            $hook = $this->findHooks($app, $workspaces, false, $url);
            if (isset($hook['uuid'])) {
                return ['status' => true];
            }
        }

        return $this->doAddHook($app, $repoId, true);
    }

    /**
     * {@inheritdoc}
     */
    public function removeHook(App $app, int|string $repoId): ?array
    {
        if (!$repoId = $this->resolveRepoUUID($app, (string)$repoId)) {
            return null;
        }

        return $this->doRemoveHook($app, $repoId, true);
    }

    /**
     * {@inheritdoc}
     */
    protected function makeCGetRequest(array $token, string $path, array $params = []): array
    {
        $params = array_merge_recursive($this->getApiHeaders($token), $params);
        $paginator = new BitbucketResultPager($this->httpClient, $this->getApiUrl($path), $params);
        return $paginator->all();
    }

    /**
     * {@inheritdoc}
     */
    public function fetchUser(array|Request $request, array $options = [], array &$accessToken = null): array
    {
        $accessToken ??= $request instanceof Request ? $this->getAccessToken($request) : $request;
        $response = $this->makeApiRequest($accessToken, 'GET', '/user');

        $response['user_name'] = $response['username'] ?? null;
        $response['user_identifier'] = $response['username'] ?? null;
        $response['external_id'] = isset($response['uuid']) ? "{$this->name}:{$response['uuid']}" : null;

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function authenticateIO(App $app, IOInterface $io, Config $config, string $repoUrl = null): void
    {
        $token = $this->refreshToken($app);
        $urls = parse_url($this->baseUrl);

        $useApi = $this->baseUrl === 'https://bitbucket.org' && AppUtils::useApiPref($this->getConfig(), $app);

        $params = ['_driver' => $useApi ? 'git-bitbucket' : 'git'];
        $params += $this->config['composer_config'] ?? [];
        $config->merge(['config' => $params]);
        $io->setAuthentication($urls['host'], 'x-token-auth', $token['access_token']);
    }

    /**
     * {@inheritdoc}
     */
    public function receiveHooks(App $accessToken, Request $request = null, ?array $payload = null): ?array
    {
        if (empty($payload)) {
            return null;
        }

        if ($payload['push'] ?? null) {
            return $this->processPushEvent($accessToken, $payload);
        }
        if ($payload['pullrequest'] ?? null) {
            return $this->processPullRequest($accessToken, $payload);
        }
        return null;
    }

    protected function getMainBranch(App $app, string $repoId): string
    {
        $repos = $this->repositories($app);
        $repo = array_filter($repos, fn ($r) => $r['ext_ref'] === $repoId || $r['name'] === $repoId);
        $repo = $repo ? reset($repo) : [];
        return $repo['mainbranch']['name'] ?? 'main';
    }

    protected function processPushEvent(App $app, array $payload): ?array
    {
        $repository = $payload['repository'] ?? [];
        $repoName = $repository['full_name'];
        $baseUrl = $repository['links']['html']['href'] ?? null;
        $sshUrl = 'git@' . parse_url($this->baseUrl, PHP_URL_HOST) . ':' . $repoName.'.git';

        $data = [
            'id' => $repoName,
            'name' => $repoName,
            'with_cache' => false,
            'external_ref' => "{$this->name}:{$repository['uuid']}",
            'default_branch' => $this->getMainBranch($app, $repoName),
            'urls' => [
                $baseUrl,
                $baseUrl.'.git',
                $sshUrl,
            ]
        ];

        return $this->pushEventSynchronize($app, $data + $payload);
    }

    /**
     * {@inheritdoc}
     */
    protected function processPullRequest(App $app, array $payload): ?array
    {
        $pullRequest = $payload['pullrequest'];
        $repoName = $payload['repository']['full_name'];

        $data = [
            'id' => $repoName,
            'name' => $repoName,
            'source_id' => $pullRequest['source']['repository']['full_name'],
            'target_id' => $pullRequest['destination']['repository']['full_name'],
            'source_branch' => $pullRequest['source']['commit']['hash'],
            'target_branch' => $pullRequest['destination']['commit']['hash'],
            'default_branch' => $pullRequest['destination']['branch']['name'],
            'iid' => $iid = $pullRequest['id'],
        ];

        return $this->pullRequestReview($app, $data, function (string $method, string $diff, $commentId = null) use ($app, $repoName, $iid) {
            $token = $this->refreshToken($app);
            $body = ['content' => ['raw' => $diff]];
            if ($method === 'PUT') {
                return $this->makeApiRequest($token, "PUT", "/repositories/$repoName/pullrequests/$iid/comments/$commentId", ['json' => $body]);
            }
            if ($method === 'POST') {
                return $this->makeApiRequest($token, "POST", "/repositories/$repoName/pullrequests/$iid/comments", ['json' => $body]);
            }
            return [];
        });
    }

    /**
     * {@inheritdoc}
     */
    public function createUser(array $userData): User
    {
        $user = new User();
        $user->setEnabled(true)
            ->setRoles($this->getConfig()->roles())
            ->setEmail($userData['username'] . '@example.com')
            ->setUsername($userData['username'])
            ->setGithubId($userData['external_id'] ?? null)
            ->generateApiToken();

        return $user;
    }

    /**
     * {@inheritdoc}
     */
    protected function getApiUrl(string $path): string
    {
        $apiVer = $this->config['api_version'] ?? '2.0';
        return ($this->baseUrl === 'https://bitbucket.org' ? $this->apiUrl : $this->baseUrl) . '/'. trim($apiVer, '/') . '/' . ltrim($path, '/');
    }
}
