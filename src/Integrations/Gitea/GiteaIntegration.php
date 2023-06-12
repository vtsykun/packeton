<?php

declare(strict_types=1);

namespace Packeton\Integrations\Gitea;

use Composer\Config;
use Composer\IO\IOInterface;
use Packeton\Entity\OAuthIntegration as App;
use Packeton\Integrations\Github\GitHubIntegration;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface as UG;

class GiteaIntegration extends GitHubIntegration
{
    protected string $baseUrl = '';
    protected array $baseScores = ['read:user'];
    protected array $appScores = ['read:user', 'organization', 'repository', 'write:issue'];
    protected ?string $authorizationResponseRoute = 'oauth_auto_redirect';
    protected string $paginatorQueryName = 'limit';

    /**
     * {@inheritdoc}
     */
    protected function init(): void
    {
        $this->remoteContentUrl = '/repos/{project_id}/contents/{file}?ref={ref}';

        if (empty($this->config['default_roles'])) {
            $this->config['default_roles'] = ['ROLE_MAINTAINER', 'ROLE_GITEA'];
        }
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
    public function authenticateIO(App $app, IOInterface $io, Config $config, string $repoUrl = null): void
    {
        $token = $this->refreshToken($app);
        $urls = parse_url($this->baseUrl);

        $params = ['_driver' => 'git'];
        $params += $this->config['composer_config'] ?? [];

        $config->merge(['config' => $params]);
        $io->setAuthentication($urls['host'], $token['access_token'], 'x-oauth-basic');
    }

    /**
     * {@inheritdoc}
     */
    public function organizations(App $app, bool $withCache = true): array
    {
        $organizations = $this->getCached($app->getId(), 'orgs', $withCache, function () use ($app) {
            $accessToken = $this->refreshToken($app);
            return $this->makeCGetRequest($accessToken, '/user/orgs');
        });

        $organizations = array_map(function ($org) {
            $org['identifier'] = $org['name'];
            $org['logo'] = $org['avatar_url'] ?? null;
            return $org;
        }, $organizations);

        return array_merge([$this->ownOrg], $organizations);
    }

    /**
     * {@inheritdoc}
     */
    protected function getWebhookBody(string $url): array
    {
        return ['type' => 'gitea', 'active' => true, 'config' => ['url' => $url, 'content_type' => 'json'], 'events' => ['push', 'pull_request', 'pull_request_sync']];
    }

    /**
     * {@inheritdoc}
     */
    protected function getRemoteContent(array $token, string|int $projectId, string $file, ?string $ref = null, bool $asJson = true): null|string|array
    {
        $ref = $ref ? "?ref=$ref" : '';
        try {
            $data = $this->makeApiRequest($token, 'GET', "/repos/$projectId/contents/$file{$ref}");
        } catch (\Throwable $e) {
            return null;
        }

        $content = base64_decode($data['content'] ?? '') ?: null;
        $content = $asJson && $content ? json_decode($content, true) : $content;

        return $asJson ? (is_array($content) ? $content : null) : $content;
    }

    /**
     * {@inheritdoc}
     */
    protected function getApiUrl(string $path): string
    {
        $apiVer = $this->config['api_version'] ?? 'v1';
        return $this->baseUrl . '/api/' . $apiVer . '/'. ltrim($path, '/');
    }
}
