<?php

declare(strict_types=1);

namespace Packeton\Integrations\Keycloak;

use Packeton\Entity\User;
use Packeton\Integrations\Base\BaseIntegrationTrait;
use Packeton\Integrations\IntegrationInterface;
use Packeton\Integrations\LoginInterface;
use Packeton\Integrations\Model\OAuth2State;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface as UG;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class KeycloakIntegration implements IntegrationInterface, LoginInterface
{
    use BaseIntegrationTrait;

    protected $pathAuthorize = '/protocol/openid-connect/auth';
    protected $pathToken = '/protocol/openid-connect/token';
    protected $pathUserInfo = '/protocol/openid-connect/userinfo';
    protected $separator = ' ';

    protected $baseUrl = '';
    protected $name;

    public function __construct(
        protected array $config,
        protected HttpClientInterface $httpClient,
        protected RouterInterface $router,
        protected OAuth2State $state,
        protected LoggerInterface $logger,
    ) {
        $this->name = $config['name'];
        if (empty($this->config['default_roles'])) {
            $this->config['default_roles'] = ['ROLE_MAINTAINER', 'ROLE_KEYCLOAK'];
        }

        if ($config['base_url'] ?? false) {
            $this->baseUrl = rtrim($config['base_url'], '/');
        }

        if (isset($config['realm'])) {
            $realmPath = '/realms/' . $config['realm'];
            $this->pathAuthorize = $realmPath . $this->pathAuthorize;
            $this->pathToken = $realmPath . $this->pathToken;
            $this->pathUserInfo = $realmPath . $this->pathUserInfo;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function redirectOAuth2Url(?Request $request = null, array $options = []): Response
    {
        $options = $options + ['scope' => ['openid', 'profile', 'email']];
        return $this->getAuthorizationResponse($this->baseUrl . $this->pathAuthorize, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(Request $request, array $options = []): array
    {
        if (!$request->get('code') || !$this->checkState($request->get('state'))) {
            throw new BadRequestHttpException('No "code" and "state" parameter was found!');
        }

        $route = $this->state->getStateBag()->get('route');
        $redirectUrl = $this->router->generate($route, ['alias' => $this->name], UG::ABSOLUTE_URL);

        $query = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => $request->get('code'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUrl,
        ];

        $response = $this->httpClient->request('POST', $this->baseUrl . $this->pathToken, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => $query
        ]);

        return $response->toArray() + ['redirect_uri' => $redirectUrl];
    }

    /**
     * {@inheritdoc}
     */
    public function fetchUser(Request|array $request, array $options = [], ?array &$accessToken = null): array
    {
        $accessToken ??= $request instanceof Request ? $this->getAccessToken($request) : $request;

        $response = $this->httpClient->request('GET', $this->baseUrl . $this->pathUserInfo, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken['access_token']
            ]
        ])->toArray();

        $response['user_name'] = $response['preferred_username'] ?? null;
        $response['user_identifier'] = $response['email'];
        $response['external_id'] = isset($response['sub']) ? "{$this->name}:{$response['sub']}" : null;
        $response['_type'] = self::LOGIN_EMAIL;

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
            ->setUsername($data['preferred_username'] ?? ($this->name . ($data['sub'] ?? '')))
            ->setGithubId($data['external_id'] ?? null)
            ->generateApiToken();

        return $user;
    }

    public function getRedirectUrls(): array
    {
        return [
            $this->router->generate('oauth_check', ['alias' => $this->name], UG::ABSOLUTE_URL)
        ];
    }
}