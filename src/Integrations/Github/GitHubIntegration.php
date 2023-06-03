<?php

declare(strict_types=1);

namespace Packeton\Integrations\Github;

use Composer\Config;
use Composer\IO\IOInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\OAuthIntegration;
use Packeton\Entity\User;
use Packeton\Integrations\AppInterface;
use Packeton\Integrations\Base\AppIntegrationTrait;
use Packeton\Integrations\Base\BaseIntegrationTrait;
use Packeton\Integrations\IntegrationInterface;
use Packeton\Integrations\LoginInterface;
use Packeton\Integrations\Model\IntegrationUtils;
use Packeton\Integrations\Model\OAuth2State;
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

    protected $baseUrl = 'https://github.com';
    protected $apiUrl = 'https://api.github.com';
    protected $name;

    public function __construct(
        protected array $config,
        protected HttpClientInterface $httpClient,
        protected RouterInterface $router,
        protected OAuth2State $state,
        protected LockFactory $lock,
        protected ManagerRegistry $registry,
        protected \Redis $redis,
        protected LoggerInterface $logger,
    ) {
        $this->name = $config['name'];
        $this->config['oauth2_registration']['default_roles'] ??= ['ROLE_MAINTAINER', 'ROLE_GITHUB'];
        $this->config['oauth2_registration']['default_roles'][] = 'ROLE_GITHUB';

        if ($config['base_url'] ?? false) {
            $this->baseUrl = rtrim($config['base_url'], '/');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function redirectOAuth2Url(Request $request = null, array $options = []): RedirectResponse
    {
        $options = $options + ['scope' => ['user:email']];

        return $this->getAuthorizationResponse($this->baseUrl . '/login/oauth/authorize', $options);
    }

    /**
     * {@inheritdoc}
     */
    public function redirectOAuth2App(Request $request = null, array $options = []): RedirectResponse
    {
        $options = $options + ['scope' => ['read:org', 'repo', 'admin:org_hook', 'admin:repo_hook']];

        return $this->getAuthorizationResponse($this->baseUrl . '/login/oauth/authorize', $options, 'oauth_install');
    }

    /**
     * {@inheritdoc}
     */
    public function organizations(OAuthIntegration $app, bool $withCache = true): array
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
    public function repositories(OAuthIntegration $app): array
    {
        $orgs = $app->getEnabledOrganizations();
        $accessToken = null;

        $allRepos = [];
        $callback = function() use ($app, &$accessToken, &$url) {
            $accessToken ??= $this->refreshToken($app);
            $param = $this->getApiHeaders($accessToken);
            $pager = new GithubResultPager($this->httpClient, 'GET', $this->getApiUrl($url), $param);
            return $pager->all();
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

    protected function formatRepos(array $repos): array
    {
        return array_map(function ($repo) {
            $required = [
                'name' => $repo['full_name'],
                'label' => $repo['full_name'],
                'ext_ref' => $this->name.':'.$repo['id'],
                'url' => $repo['clone_url'],
                'ssh_url' => $repo['ssh_url'],
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

        $response['user_identifier'] = $response['email'];
        $response['external_id'] = isset($response['id']) ? $this->getConfig()->getName() . ':' . $response['id'] : null;

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function authenticateIO(OAuthIntegration $oauth2, IOInterface $io, Config $config, string $repoUrl = null): void
    {
        $token = $this->refreshToken($oauth2);
        $urls = parse_url($this->baseUrl);

        $params = [
            '_driver' => 'github',
            '_no_api' => !IntegrationUtils::useApiPref($this->getConfig(), $oauth2),
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
