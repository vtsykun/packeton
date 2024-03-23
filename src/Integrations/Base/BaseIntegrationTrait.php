<?php

declare(strict_types=1);

namespace Packeton\Integrations\Base;

use Okvpn\Expression\TwigLanguage;
use Packeton\Entity\OAuthIntegration;
use Packeton\Entity\User;
use Packeton\Integrations\Model\AppConfig;
use Packeton\Integrations\Model\OAuth2ExpressionExtension;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

trait BaseIntegrationTrait
{
    protected ?TwigLanguage $exprLang = null;

    /**
     * {@inheritdoc}
     */
    public function getConfig(?OAuthIntegration $app = null, bool $details = false): AppConfig
    {
        return new AppConfig($this->config + $this->getConfigApp($app, $details));
    }

    /**
     * {@inheritdoc}
     */
    public function evaluateExpression(array $context = [], ?string $scriptPayload = null): mixed
    {
        if (null === $this->exprLang) {
            $this->initExprLang();
        }

        $script = trim($this->getConfig()->getLoginExpression());
        if (str_starts_with($script, '/') && file_exists($script)) {
            if ($scriptPayload === $script) {
                $scriptPayload = null;
            }
            $script = file_get_contents($script);
        }

        $scriptPayload ??= $script;
        if (!str_contains($scriptPayload, '{%')) {
            $scriptPayload = "{% return $scriptPayload %}";
        }

        return $this->exprLang->execute($scriptPayload, $context, true);
    }

    protected function initExprLang(): void
    {
        $this->exprLang = isset($this->twigLanguage) ? clone $this->twigLanguage : new TwigLanguage();
        $repo = $this->registry->getRepository(OAuthIntegration::class);

        $apiCallable = function(string $action, string $url, array $query = [], bool $cache = true, ?int $app = null) use ($repo) {
            $baseApp = $app ? $repo->find($app) : $repo->findForExpressionUsage($this->name);
            $key = "twig-expr:" . sha1(serialize([$action, $url, $query]));

            return $this->getCached($baseApp, $key, $cache, function () use ($baseApp, $url, $action, $query) {
                $token = $this->refreshToken($baseApp);
                return match ($action) {
                    'cget' => $this->makeCGetRequest($token, $url, ['query' => $query]),
                    default => $this->makeApiRequest($token, 'GET', $url, ['query' => $query]),
                };
            });
        };

        $funcList = [
            'api_cget' => fn () => call_user_func_array($apiCallable, array_merge(['cget'], func_get_args())),
            'api_get' => fn () => call_user_func_array($apiCallable, array_merge(['get'], func_get_args()))
        ];

        $this->exprLang->addExtension(new OAuth2ExpressionExtension($funcList));
    }

    /**
     * {@inheritdoc}
     */
    protected function getConfigApp(?OAuthIntegration $app = null, bool $details = false): array
    {
        $config = [];
        if ($app instanceof OAuthIntegration) {
            $params = ['alias' => $this->name, 'id' => $app->getId(), 'token' => $app->getHookToken()];
            if (($baseUrl = $this->config['webhook_url'] ?? null)) {
                $baseUrl = rtrim($baseUrl, '/');
                $components = parse_url($baseUrl);
                if (isset($components['path'])) {
                    $config['hook_url'] = $baseUrl . "?token={$app->getHookToken()}";
                } else {
                    $config['hook_url'] = $baseUrl . $this->router->generate('api_integration_postreceive', $params);
                }
            } else {
                $config['hook_url'] = $this->router->generate('api_integration_postreceive', $params, 0);
            }
        }

        if ($app || $details) {
            $config['redirect_urls'] = $this->getRedirectUrls();
        }

        return $config;
    }

    public function getRedirectUrls(): array
    {
        $base = $this->router->generate('home', referenceType: UrlGeneratorInterface::ABSOLUTE_URL);
        return [$base];
    }

    protected function getAuthorizationResponse(string $baseUrl, array $options, ?string $route = null): RedirectResponse
    {
        $route ??= 'oauth_check';
        $bag = $this->state->getStateBag();

        $bag->set('route', $route);
        $bag->set('state', $state = md5(random_bytes(16)));
        $options['state'] = $state;

        $response = new RedirectResponse($this->getAuthorizationUrl($baseUrl, $options, $route));
        $this->state->save($response);

        return $response;
    }

    protected function checkState($state): bool
    {
        $bag = $this->state->getStateBag();

        return $bag->get('state') === $state && !empty($state);
    }

    protected function getAuthorizationUrl(string $baseUrl, array $options, string $route = 'oauth_check'): string
    {
        $params = $this->getAuthorizationParameters($options, $route);
        $query = http_build_query($params, '', '&', \PHP_QUERY_RFC3986);

        return $baseUrl . '?' . $query;
    }

    protected function getAuthorizationParameters(array $options, string $route = 'oauth_check'): array
    {
        if (empty($options['scope'] ?? [])) {
            $options['scope'] = $this->defaultScopes ?? [];
        }

        $options += [
            'response_type'   => 'code',
            'approval_prompt' => 'auto'
        ];

        $separator = $this->separator ?? ',';
        if (is_array($options['scope'])) {
            $options['scope'] = implode($separator, $options['scope']);
        }

        $options['client_id'] = $this->config['client_id'];
        if (!isset($options['redirect_uri'])) {
            $options['redirect_uri'] = $this->router->generate($route, ['alias' => $this->config['name']], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        if (empty($options['redirect_uri'])) {
            unset($options['redirect_uri']);
        }

        return $options;
    }

    public function createUser(array $data): User
    {
        $username = $data['user_name'] ?? $data['user_identifier'];
        $username = preg_replace('#[^a-z0-9-_]#i', '_', $username);
        $email = $data['email'] ?? (str_contains($data['user_identifier'], '@') ? $data['user_identifier'] : $data['user_identifier'] .'@example.com');

        $user = new User();
        $user->setEnabled(true)
            ->setRoles($this->getConfig()->roles())
            ->setEmail($email)
            ->setUsername($username)
            ->setGithubId($data['external_id'] ?? null)
            ->generateApiToken();

        return $user;
    }
}
