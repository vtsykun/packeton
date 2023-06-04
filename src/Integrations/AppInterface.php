<?php

declare(strict_types=1);

namespace Packeton\Integrations;

use Composer\Config;
use Composer\IO\IOInterface;
use Packeton\Entity\OAuthIntegration as App;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface AppInterface extends IntegrationInterface
{
    public function redirectOAuth2App(Request $request = null, array $options = []): Response;

    public function getAccessToken(Request $request, array $options = []): array;

    public function refreshToken(array|App $accessToken, array $options = []): array;

    /**
     * Returns a list of repos a given integration name
     *
     * @param App $accessToken
     *
     * @return array<int, array{name: string, label: string, url: string, ssh_url: string, ext_ref: string|int}>
     */
    public function repositories(App $accessToken): array;

    /**
     * Returns a list of organizations a given integration name
     *
     * @param App $accessToken
     *
     * @return array<int, array{identifier: string, name: string, logo: string}>
     */
    public function organizations(App $accessToken): array;

    public function cacheClear(string|int $appId): void;

    public function addHook(App $accessToken, int|string $repoId): ?array;

    public function removeHook(App $accessToken, int|string $repoId): ?array;

    public function addOrgHook(App $accessToken, int|string $orgId): ?array;

    public function removeOrgHook(App $accessToken, int|string $orgId): ?array;

    public function receiveHooks(Request $request, ?array $payload, App $app): ?array;

    public function findApps(): array;

    public function authenticateIO(App $accessToken, IOInterface $io, Config $config): void;
}
