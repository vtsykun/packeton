<?php

declare(strict_types=1);

namespace Packeton\Integrations\Base;

use Composer\Config;
use Composer\IO\IOInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\OAuthIntegration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;

trait AppIntegrationTrait
{
    protected \Redis $redis;
    protected LockFactory $lock;
    protected ManagerRegistry $registry;

    public function refreshToken(array|OAuthIntegration $token, array $options = []): array
    {
        return $token instanceof OAuthIntegration ? $this->refreshTokenWithLock($token) : $token;
    }

    protected function refreshTokenWithLock(OAuthIntegration $oauth): array
    {
        $accessToken = $oauth->getAccessToken();
        if (!$this->isTokenExpired($accessToken)) {
            return $accessToken;
        }

        $em = $this->registry->getManager();
        $lock = $this->lock->createLock("oauth:{$oauth->getId()}");

        $timeout = time() + 10;
        while (time() < $timeout) {
            if ($lock->acquire()) {
                try {
                    $oauth->setAccessToken($this->doRefreshToken($accessToken));
                    $em->flush();
                    return $oauth->getAccessToken();
                } finally {
                    $lock->release();
                }
            }

            sleep(2);
            $em->refresh($oauth);
            if (!$this->isTokenExpired($accessToken = $oauth->getAccessToken())) {
                return $accessToken;
            }
        }

        return $accessToken;
    }

    protected function doRefreshToken(array $token): array
    {
        return $token;
    }

    protected function isTokenExpired(array $token): bool
    {
        return false;
    }

    public function repositories(OAuthIntegration $accessToken): array
    {
        return [];
    }

    public function cacheClear($appId): void
    {
        $this->redis->del("oauthapp:{$this->name}:$appId");
    }

    public function organizations(OAuthIntegration $accessToken): array
    {
        return [];
    }

    public function addHook(array|OAuthIntegration $accessToken, int|string $repositoryId): void
    {
    }

    public function receiveHooks(Request $request, array $payload): bool
    {
        return false;
    }

    public function findApps(): array
    {
        return $this->registry->getRepository(OAuthIntegration::class)->findBy(['alias' => $this->name]);
    }

    public function removeHook(OAuthIntegration $accessToken, int|string $repositoryId): void
    {
    }

    public function addOrgHook(OAuthIntegration $accessToken, int|string $orgId): void
    {
    }

    public function removeOrgHook(OAuthIntegration $accessToken, int|string $orgId): void
    {
    }

    public function zipballDownload(OAuthIntegration $accessToken, string $reference): string
    {
        throw new \LogicException('Zipball Download is not supported');
    }

    public function authenticateIO(OAuthIntegration $accessToken, IOInterface $io, Config $config, string $repoUrl = null): void
    {
    }

    protected function getCached(string|int $appId, string $key, bool $withCache = true, callable $callback = null): mixed
    {
        if (true === $withCache) {
            try {
                $result = $this->redis->hGet("oauthapp:{$this->name}:$appId", $key);
                if (is_string($result)) {
                    return json_decode($result, true);
                }
            } catch (\Exception $e) {
            }
        }

        $result = call_user_func($callback);
        $this->setCached($appId, $key, $result);
        return $result;
    }

    protected function setCached(string|int $appId, string $key, mixed $value): void
    {
        $this->redis->hSet("oauthapp:{$this->name}:$appId", $key, json_encode($value));
    }
}
