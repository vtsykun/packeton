<?php

declare(strict_types=1);

namespace Packeton\Integrations\Gitlab;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\User;
use Packeton\Integrations\AppInterface;
use Packeton\Integrations\Base\AppIntegrationTrait;
use Packeton\Integrations\Base\BaseIntegrationTrait;
use Packeton\Integrations\IntegrationInterface;
use Packeton\Integrations\LoginInterface;
use Packeton\Integrations\Model\OAuth2State;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface as UG;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GitLabIntegration implements IntegrationInterface, LoginInterface, AppInterface
{
    use BaseIntegrationTrait;
    use AppIntegrationTrait;

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
        protected LockFactory $lock,
        protected ManagerRegistry $registry,
        protected \Redis $redis,
        protected LoggerInterface $logger,
    ) {
        $this->name = $config['name'];
        $this->config['oauth2_registration']['default_roles'] ??= ['ROLE_MAINTAINER', 'ROLE_GITLAB'];
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

        $params = $this->getApiHeaders($accessToken);
        $response = $this->httpClient->request('GET', $this->getApiUrl('/user'), $params);
        $response = $response->toArray();

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

    protected function getApiUrl(string $path): string
    {
        return $this->baseUrl . '/api/v4' . $path;
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
