<?php

declare(strict_types=1);

namespace Packeton\Integrations\Oidc;

use Packeton\Attribute\AsIntegration;
use Packeton\Integrations\Base\BaseIntegrationTrait;
use Packeton\Integrations\LoginInterface;
use Packeton\Integrations\Model\OAuth2State;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface as UG;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsIntegration(OidcOAuth2Factory::class)]
class OidcOAuth2Login implements LoginInterface
{
    use BaseIntegrationTrait;

    protected $name;
    protected $separator = ' ';
    protected $defaultScopes = ['openid', 'email', 'profile'];
    protected ?array $discoveryCache = null;

    public function __construct(
        protected array $config,
        protected HttpClientInterface $httpClient,
        protected RouterInterface $router,
        protected OAuth2State $state,
        protected \Redis $redis,
        protected LoggerInterface $logger,
    ) {
        $this->name = $config['name'];
        if (empty($this->config['default_roles'])) {
            $this->config['default_roles'] = ['ROLE_MAINTAINER', 'ROLE_SSO_OIDC'];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function redirectOAuth2Url(?Request $request = null, array $options = []): Response
    {
        $discovery = $this->getDiscovery();
        $scopes = $this->config['scopes'] ?? $this->defaultScopes;
        $options['scope'] = $scopes;

        return $this->getAuthorizationResponse($discovery['authorization_endpoint'], $options);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(Request $request, array $options = []): array
    {
        if (!$request->get('code') || !$this->checkState($request->get('state'))) {
            throw new BadRequestHttpException('No "code" and "state" parameter was found (usually this is a query parameter)!');
        }

        $discovery = $this->getDiscovery();
        $route = $this->state->getStateBag()->get('route');
        $redirectUrl = $this->router->generate($route, ['alias' => $this->name], UG::ABSOLUTE_URL);

        $body = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => $request->get('code'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUrl,
        ];

        $response = $this->httpClient->request('POST', $discovery['token_endpoint'], ['body' => $body]);

        return $response->toArray();
    }

    /**
     * {@inheritdoc}
     */
    public function fetchUser(array|Request $request, array $options = [], ?array &$accessToken = null): array
    {
        $accessToken ??= $request instanceof Request ? $this->getAccessToken($request) : $request;
        $discovery = $this->getDiscovery();

        $response = $this->httpClient->request(
            'GET',
            $discovery['userinfo_endpoint'],
            $this->getAuthorizationHeaders($accessToken)
        );

        $claims = $response->toArray();

        return $this->mapClaims($claims);
    }

    protected function getDiscovery(): array
    {
        if ($this->discoveryCache !== null) {
            return $this->discoveryCache;
        }

        $cacheKey = "oidc:discovery:{$this->name}";
        $cached = $this->redis->get($cacheKey);

        if ($cached) {
            $this->discoveryCache = json_decode($cached, true);
            return $this->discoveryCache;
        }

        $discoveryUrl = $this->getDiscoveryUrl();
        $response = $this->httpClient->request('GET', $discoveryUrl);
        $discovery = $response->toArray();

        $required = ['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'issuer'];
        foreach ($required as $field) {
            if (empty($discovery[$field])) {
                throw new \RuntimeException("OIDC discovery missing required field: $field");
            }
        }

        $this->redis->setex($cacheKey, 3600, json_encode($discovery));
        $this->discoveryCache = $discovery;

        return $discovery;
    }

    protected function getDiscoveryUrl(): string
    {
        if ($url = ($this->config['discovery_url'] ?? null)) {
            return rtrim($url, '/');
        }

        if ($issuer = ($this->config['issuer'] ?? null)) {
            return rtrim($issuer, '/') . '/.well-known/openid-configuration';
        }

        throw new \RuntimeException('OIDC requires either discovery_url or issuer configuration');
    }

    protected function mapClaims(array $claims): array
    {
        $mapping = $this->config['claim_mapping'] ?? [];

        $this->logger->info('OIDC [{name}]: Starting claims mapping', ['name' => $this->name]);
        $this->logger->debug('OIDC [{name}]: Raw claims keys', ['name' => $this->name, 'claims' => array_keys($claims)]);
        $this->logger->debug('OIDC [{name}]: Claim mapping config', ['name' => $this->name, 'mapping' => $mapping]);

        $email = $claims[$mapping['email'] ?? 'email'] ?? null;
        $username = $claims[$mapping['username'] ?? 'preferred_username']
            ?? $claims['nickname']
            ?? ($email ? explode('@', $email)[0] : null);
        $sub = $claims[$mapping['sub'] ?? 'sub'] ?? null;

        $this->logger->info('OIDC [{name}]: Resolved user - username: {username}, email: {email}, sub: {sub}', [
            'name' => $this->name,
            'username' => $username,
            'email' => $email,
            'sub' => $sub,
        ]);

        $requireVerified = $this->config['require_email_verified'] ?? true;
        if ($requireVerified && isset($claims['email_verified']) && $claims['email_verified'] === false) {
            throw new BadRequestHttpException('OIDC email_verified is false!');
        }

        $claims['user_name'] = $username;
        $claims['user_identifier'] = $email;
        $claims['external_id'] = $sub ? "{$this->name}:{$sub}" : null;
        $claims['_type'] = self::LOGIN_EMAIL;

        $rolesClaim = $mapping['roles_claim'] ?? null;
        if ($rolesClaim) {
            $this->logger->debug('OIDC [{name}]: Looking for roles in claim "{claim}"', [
                'name' => $this->name,
                'claim' => $rolesClaim,
            ]);

            if (isset($claims[$rolesClaim])) {
                $oidcGroups = (array) $claims[$rolesClaim];
                $this->logger->info('OIDC [{name}]: Found {count} groups in claim "{claim}"', [
                    'name' => $this->name,
                    'count' => count($oidcGroups),
                    'claim' => $rolesClaim,
                ]);
                $this->logger->debug('OIDC [{name}]: Groups from token', ['name' => $this->name, 'groups' => $oidcGroups]);

                $mappedRoles = $this->mapRoles($oidcGroups);
                if (!empty($mappedRoles)) {
                    $claims['_mapped_roles'] = $mappedRoles;
                    $this->logger->info('OIDC [{name}]: Mapped {count} roles', [
                        'name' => $this->name,
                        'count' => count($mappedRoles),
                    ]);
                } else {
                    $this->logger->info('OIDC [{name}]: No roles mapped from groups', ['name' => $this->name]);
                }
            } else {
                $this->logger->info('OIDC [{name}]: Roles claim "{claim}" not found in token', [
                    'name' => $this->name,
                    'claim' => $rolesClaim,
                ]);
            }
        }

        return $claims;
    }

    protected function mapRoles(array $oidcGroups): array
    {
        $rolesMap = $this->config['claim_mapping']['roles_map'] ?? [];
        if (empty($rolesMap)) {
            $this->logger->debug('OIDC [{name}]: No roles_map configured', ['name' => $this->name]);
            return [];
        }

        $this->logger->debug('OIDC [{name}]: Configured roles_map keys', [
            'name' => $this->name,
            'configured_groups' => array_keys($rolesMap),
        ]);

        $roles = [];
        $matchedGroups = [];
        $unmatchedGroups = [];

        foreach ($oidcGroups as $group) {
            if (isset($rolesMap[$group])) {
                $mappedTo = (array) $rolesMap[$group];
                $roles = array_merge($roles, $mappedTo);
                $matchedGroups[] = $group;
                $this->logger->debug('OIDC [{name}]: Group "{group}" matched -> {roles}', [
                    'name' => $this->name,
                    'group' => $group,
                    'roles' => $mappedTo,
                ]);
            } else {
                $unmatchedGroups[] = $group;
                $this->logger->debug('OIDC [{name}]: Group "{group}" has no mapping', [
                    'name' => $this->name,
                    'group' => $group,
                ]);
            }
        }

        if (!empty($unmatchedGroups)) {
            $this->logger->info('OIDC [{name}]: Unmatched groups (not in roles_map): {groups}', [
                'name' => $this->name,
                'groups' => $unmatchedGroups,
            ]);
        }

        $uniqueRoles = array_unique($roles);
        $this->logger->debug('OIDC [{name}]: Final mapped roles', [
            'name' => $this->name,
            'roles' => $uniqueRoles,
            'matched_groups' => $matchedGroups,
        ]);

        return $uniqueRoles;
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
