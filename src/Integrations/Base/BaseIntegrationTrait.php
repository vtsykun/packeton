<?php

declare(strict_types=1);

namespace Packeton\Integrations\Base;

use Packeton\Entity\OAuthIntegration;
use Packeton\Integrations\Model\AppConfig;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

trait BaseIntegrationTrait
{
    protected $defaultScopes = [''];

    /**
     * {@inheritdoc}
     */
    public function getConfig(OAuthIntegration $app = null, bool $details = false): AppConfig
    {
        return new AppConfig($this->config + $this->getConfigApp($app, $details));
    }

    /**
     * {@inheritdoc}
     */
    protected function getConfigApp(OAuthIntegration $app = null, bool $details = false): array
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

    protected function getAuthorizationResponse(string $baseUrl, array $options, string $route = 'oauth_check'): RedirectResponse
    {
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
            $options['scope'] = $this->defaultScopes;
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
        if (empty($options['redirect_uri'])) {
            $options['redirect_uri'] = $this->router->generate($route, ['alias' => $this->config['name']], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $options;
    }
}
