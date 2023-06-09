<?php

declare(strict_types=1);

namespace Packeton\Integrations\Base;

use Composer\Config;
use Composer\IO\IOInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\OAuthIntegration;
use Packeton\Integrations\Model\CustomCacheItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;

trait AppIntegrationTrait
{
    protected $ownOrg = ['name' => 'Own profile', 'identifier' => '@self'];

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

    public function receiveHooks(OAuthIntegration $app, Request $request = null, ?array $payload = null): ?array
    {
        return null;
    }

    public function findApps(): array
    {
        return $this->registry->getRepository(OAuthIntegration::class)->findBy(['alias' => $this->name]);
    }

    public function addHook(array|OAuthIntegration $accessToken, int|string $repositoryId): ?array
    {
        return null;
    }

    public function removeHook(OAuthIntegration $accessToken, int|string $repositoryId): ?array
    {
        return null;
    }

    public function addOrgHook(OAuthIntegration $accessToken, int|string $orgId): ?array
    {
        return null;
    }

    public function removeOrgHook(OAuthIntegration $accessToken, int|string $orgId): ?array
    {
        return null;
    }

    public function zipballDownload(OAuthIntegration $accessToken, string $reference): string
    {
        throw new \LogicException('Zipball Download is not supported');
    }

    public function authenticateIO(OAuthIntegration $accessToken, IOInterface $io, Config $config, string $repoUrl = null): void
    {
    }

    protected function getCached(string|int|OAuthIntegration $appId, string $key, bool $withCache = true, callable $callback = null): mixed
    {
        $appId = $appId instanceof OAuthIntegration ? $appId->getId() : $appId;
        if (true === $withCache) {
            try {
                $result = $this->redis->hGet("oauthapp:{$this->name}:$appId", $key);
                if (is_string($result)) {
                    $result = json_decode($result, true);
                    if (isset($result['__flag_'])) {
                        if (!isset($result['exp_at']) || $result['exp_at'] > time()) {
                            return $result['__item'] ?? null;
                        }
                    } else {
                        return $result;
                    }
                }
            } catch (\Exception $e) {
            }
        }

        $item = new CustomCacheItem($key);
        if (null !== $callback) {
            $result = call_user_func($callback, $item);
            $item->set($result);

            $this->setCached($appId, $key, $result);
            return $result;
        }
        return null;
    }

    protected function setCached(string|int|OAuthIntegration $appId, string $key, mixed $value): void
    {
        $appId = $appId instanceof OAuthIntegration ? $appId->getId() : $appId;
        if ($value instanceof CustomCacheItem) {
            $value = ['__flag_' => 1, 'exp_at' => $value->getExpiry(), '__item' => $value->get()];
        }

        $this->redis->hSet("oauthapp:{$this->name}:$appId", $key, json_encode($value, JSON_UNESCAPED_SLASHES));
    }
}
