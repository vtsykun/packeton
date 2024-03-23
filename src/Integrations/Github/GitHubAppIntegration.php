<?php

declare(strict_types=1);

namespace Packeton\Integrations\Github;

use Composer\Config;
use Composer\IO\IOInterface;
use Firebase\JWT\JWT;
use Packeton\Entity\OAuthIntegration as App;
use Packeton\Form\Type\IntegrationGitHubAppType;
use Packeton\Integrations\Exception\GitHubAppException;
use Packeton\Integrations\Model\FormSettingsInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface as UG;

class GitHubAppIntegration extends GitHubIntegration implements FormSettingsInterface
{
    /**
     * {@inheritdoc}
     */
    public function redirectOAuth2App(?Request $request = null, array $options = []): RedirectResponse
    {
        if (!empty($this->config['client_id']) && !empty($this->config['client_secret'])) {
            return parent::redirectOAuth2App($request, $options);
        }

        $em = $this->registry->getManager();
        $username = $this->state->get('username');
        $app = $this->registry->getRepository(App::class)->findOneBy(['owner' => $username, 'alias' => $this->name]);
        if (null === $app) {
            $app = new App();
            $app->setOwner($username)
                ->setHookSecret(sha1(random_bytes(20)))
                ->setAlias($this->name);

            $em->persist($app);
            $em->flush();
        }

        if ($app->getInstallationId() === null) {
            $request->getSession()->getFlashBag()->add('success', 'You must manually add integration id on the settings page');
        }

        $app->setAccessToken(['access_token' => '']);
        $em->flush();

        return new RedirectResponse($this->router->generate('integration_index', ['alias' => $this->name, 'id' => $app->getId()]));
    }

    /**
     * {@inheritdoc}
     */
    public function repositories(App $app): array
    {
        if ($app->getInstallationId() === null) {
            return [];
        }

        $repos = $this->getCached($app->getId(), "repos", callback: function () use ($app) {
            $accessToken = $this->refreshToken($app);
            return $this->makeCGetRequest($accessToken, '/installation/repositories', ['column' => 'repositories']);
        });

        return $this->formatRepos($repos);
    }

    /**
     * {@inheritdoc}
     */
    public function organizations(App $app, bool $withCache = true): array
    {
        if (null === $app->getInstallationId()) {
            throw new GitHubAppException('You need to setup installation id on the settings page. You can found installation id on your GitHub account');
        }

        $organizations = [];
        $repos = $this->repositories($app);
        foreach ($repos as $repo) {
            if (($repo['owner']['type'] ?? null) === 'Organization') {
                $org = $repo['owner'];
                $org['identifier'] = $org['login'];
                $org['name'] = $org['login'];
                $org['logo'] = $org['avatar_url'] ?? null;

                $organizations[$repo['owner']['id']] = $org;
            }
        }

        return array_merge([$this->ownOrg], array_values($organizations));
    }

    /**
     * {@inheritdoc}
     */
    public function authenticateIO(App $oauth2, IOInterface $io, Config $config, ?string $repoUrl = null): void
    {
        parent::authenticateIO($oauth2, $io, $config, $repoUrl);

        if ($config->get('_no_api')) {
            $token = $this->refreshToken($oauth2);
            $domains = $config->get('github-domains') ?: [];
            foreach ($domains as $domain) {
                $io->setAuthentication($domain, 'x-access-token', $token['access_token']);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function isTokenExpired(array $token, ?App $app = null): bool
    {
        if (($token['installation_id'] ?? 'na') !== $app?->getInstallationId() || empty($token['access_token'])) {
            return true;
        }

        $expireAt = $token['expires_in'] ?? 0;
        return $expireAt < time() + 60;
    }

    /**
     * {@inheritdoc}
     */
    public function getRedirectUrls(): array
    {
        return [
            $this->router->generate('oauth_install', ['alias' => $this->name], UG::ABSOLUTE_URL),
            $this->router->generate('oauth_check', ['alias' => $this->name], UG::ABSOLUTE_URL),
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function doRefreshToken(array $token, ?App $app = null): array
    {
        if (empty($iid = $app->getInstallationId())) {
            return ['access_token' => ''];
        }

        $jwtToken = $this->genJWSToken();
        $currentTime = time();
        $accessToken = $this->makeApiRequest(['access_token' => $jwtToken], 'POST', "/app/installations/$iid/access_tokens");
        $accessToken['expires_in'] = $currentTime + 3600;
        if (isset($accessToken['expires_at'])) {
            try {
                $accessToken['expires_in'] = (new \DateTime($accessToken['expires_at']))->getTimestamp();
            } catch (\Throwable $e) {}
        }

        if (isset($accessToken['token'])) {
            $accessToken['access_token'] = $accessToken['token'];
            unset($accessToken['token']);
        }

        $accessToken['installation_id'] = $iid;

        return $accessToken;
    }

    protected function genJWSToken(): string
    {
        $rawKey = @is_file($this->config['private_key']) ?
            file_get_contents($this->config['private_key']) : $this->config['private_key'];

        $currentTime = time();
        $key = openssl_pkey_get_private($rawKey, $this->config['passphrase'] ?? null);
        $jwtData = [
            'iat' => $currentTime - 60,
            'exp' => $currentTime + 540,
            'iss' => $this->config['app_id'],
        ];

        return JWT::encode($jwtData, $key, 'RS256');
    }

    /**
     * {@inheritdoc}
     */
    public function getFormSettings(App $app): array
    {
        return [IntegrationGitHubAppType::class, []];
    }
}
