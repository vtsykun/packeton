<?php

declare(strict_types=1);

namespace Packeton\Integrations\Gitea;

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
    protected function getApiUrl(string $path): string
    {
        $apiVer = $this->config['api_version'] ?? 'v1';
        return $this->baseUrl . '/api/' . $apiVer . '/'. ltrim($path, '/');
    }
}
