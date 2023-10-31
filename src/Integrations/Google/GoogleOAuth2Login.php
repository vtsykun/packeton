<?php

declare(strict_types=1);

namespace Packeton\Integrations\Google;

use Packeton\Attribute\AsIntegration;
use Packeton\Integrations\Base\BaseIntegrationTrait;
use Packeton\Integrations\LoginInterface;
use Packeton\Integrations\Model\OAuth2State;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface as UG;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsIntegration('google')]
class GoogleOAuth2Login implements LoginInterface
{
    use BaseIntegrationTrait;

    protected $name;
    protected $separator = ' ';
    protected $defaultScopes = ['openid', 'email'];

    public function __construct(
        protected array $config,
        protected HttpClientInterface $httpClient,
        protected RouterInterface $router,
        protected OAuth2State $state,
    ) {
        $this->name = $config['name'];
        if (empty($this->config['default_roles'])) {
            $this->config['default_roles'] = ['ROLE_MAINTAINER', 'ROLE_SSO_GOOGLE'];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function redirectOAuth2Url(Request $request = null, array $options = []): Response
    {
        return $this->getAuthorizationResponse('https://accounts.google.com/o/oauth2/v2/auth', $options);
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

        $response = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', ['body' => $query]);

        return $response->toArray();
    }

    /**
     * {@inheritdoc}
     */
    public function fetchUser(array|Request $request, array $options = [], array &$accessToken = null): array
    {
        $accessToken ??= $request instanceof Request ? $this->getAccessToken($request) : $request;

        $response = $this->httpClient->request('GET', 'https://openidconnect.googleapis.com/v1/userinfo', $this->getAuthorizationHeaders($accessToken));

        $response = $response->toArray();
        if (false === ($response['email_verified'] ?? null)) {
            throw new BadRequestHttpException('Google email_verified is false!');
        }

        $response['user_name'] = explode('@', $response['email'])[0];
        $response['user_identifier'] = $response['email'];
        $response['external_id'] = isset($response['sub']) ? $this->name . ':' . $response['sub'] : null;

        return $response;
    }

    protected function getAuthorizationHeaders(array $token): array
    {
        return array_merge_recursive($this->config['http_options'] ?? [], [
            'headers' => [
                'Authorization' => "Bearer {$token['access_token']}",
            ]
        ]);
    }
}
