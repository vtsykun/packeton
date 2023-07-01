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
    protected string $paginatorQueryName = 'limit';
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

        // change
        $this->remoteContentUrl = "/projects/{project_id}/repository/files/{file}?ref={ref}";
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
        return $response->toArray();
    }

    /**
     * {@inheritdoc}
     */
    protected function isTokenExpired(array $token): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    protected function doRefreshToken(array $token): array
    {
        return $token;
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
    public function createUser(array $userData): User
    {
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
