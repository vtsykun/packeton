<?php

declare(strict_types=1);

namespace Packeton\Integrations\Base;

use Composer\Config;
use Composer\IO\IOInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\OAuthIntegration;
use Packeton\Entity\OAuthIntegration as App;
use Packeton\Entity\Package;
use Packeton\Integrations\Github\GithubResultPager;
use Packeton\Integrations\Model\AppUtils;
use Packeton\Integrations\Model\CustomCacheItem;
use Packeton\Util\ComposerDiffReview;
use Packeton\Util\PacketonUtils;
use Psr\Cache\CacheItemInterface as CacheItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;

trait AppIntegrationTrait
{
    protected $ownOrg = ['name' => 'Own profile', 'identifier' => '@self'];
    protected $remoteContentUrl = null;
    protected $remoteContentFormat = null;

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
        if (!$this->isTokenExpired($accessToken, $oauth)) {
            return $accessToken;
        }

        $em = $this->registry->getManager();
        $lock = $this->lock->createLock("oauth:{$oauth->getId()}");

        $timeout = time() + 10;
        while (time() < $timeout) {
            if ($lock->acquire()) {
                try {
                    $oauth->setAccessToken($this->doRefreshToken($accessToken, $oauth));
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

    public function cacheClear($appId, bool $isFull = false): void
    {
        $this->redis->del("oauthapp:{$this->name}:$appId");
        if ($isFull === true) {
            $this->redis->del("pull_review:$appId");
        }
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

    public function addHook(array|OAuthIntegration $accessToken, int|string $repoId): ?array
    {
        return null;
    }

    public function removeHook(OAuthIntegration $accessToken, int|string $repoId): ?array
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

    public function authenticateIO(OAuthIntegration $accessToken, IOInterface $io, Config $config, string $repoUrl = null): void
    {
    }

    protected function genCacheKey(string|int|OAuthIntegration $appId): string
    {
        $appId = $appId instanceof OAuthIntegration ? $appId->getId() : $appId;
        return "oauthapp:{$this->name}:$appId";
    }

    protected function getCached(string|int|OAuthIntegration $appId, string $key, bool $withCache = true, callable $callback = null): mixed
    {
        if (true === $withCache) {
            try {
                $result = $this->redis->hGet($this->genCacheKey($appId), $key);
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
            $this->setCached($appId, $key, $item->getExpiry() !== null ? $item : $result);
            return $result;
        }

        return null;
    }

    protected function setCached(string|int|OAuthIntegration $appId, string $key, mixed $value): void
    {
        if ($value instanceof CustomCacheItem) {
            $value = ['__flag_' => 1, 'exp_at' => $value->getExpiry(), '__item' => $value->get()];
        }

        $this->redis->hSet($this->genCacheKey($appId), $key, json_encode($value, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array{name: string, id: string, external_ref: string, organization_column: string,
     *       default_branch: string|null, commits: array, urls: array} $payload
     * {@inheritdoc}
     */
    protected function pushEventSynchronize(App $app, array $payload): ?array
    {
        $payload['external_ref'] ??= "{$this->name}:{$payload['id']}";
        $payload['organization_column'] ??= "identifier";

        $repo = $this->registry->getRepository(Package::class);
        $pkg = $repo->findOneBy(['externalRef' => $payload['external_ref']]);
        if (null !== $pkg) {
            $pkg->setAutoUpdated(true);
            $job = $this->scheduler->scheduleUpdate($pkg);
            return ['status' => 'success', 'job' => $job->getId(), 'code' => 202];
        }

        $config = $this->getConfig();
        if (!AppUtils::enableSync($config, $app) || AppUtils::isRepoExcluded($app, $payload['name'], $this->organizations($app), $payload['organization_column'])) {
            return null;
        }

        $useCache = $payload['with_cache'] ?? true;
        $hasNewComposer = $this->getCached($app, "hasComposer:{$payload['name']}", $useCache, function (CacheItem $item) use ($app, $payload, $repo) {
            $item->expiresAfter(7*86400);
            $token = $this->refreshToken($app);
            try {
                $content = $this->getRemoteContent($token, $payload['id'], 'composer.json', $payload['default_branch'] ?? null);
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage(), ['e' => $e]);
                return false;
            }

            if (!is_string($name = ($content['name'] ?? null))) {
                return false;
            }

            if ($pkg = $repo->findOneByName(strtolower($name))) {
                foreach (($payload['urls'] ?? []) as $url) {
                    if ($url && $pkg->getRepository() === $url) {
                        $pkg->setExternalRef($payload['external_ref']);
                        $this->registry->getManager()->flush();
                        break;
                    }
                }
            }

            return $pkg === null;
        });

        $newAdded = false;
        $commits = $payload['commits'] ?? [];
        foreach ($commits as $commit) {
            $files = array_merge((array)($commit['added'] ?? []), (array)($commit['modified'] ?? []));
            if (in_array('composer.json', $files)) {
                $newAdded = true;
                break;
            }
        }

        if (false === $newAdded && false === $hasNewComposer) {
            return null;
        }

        $job = null;
        $this->getCached($app, "sync:{$payload['name']}", false === $newAdded, function (CacheItem $item) use ($payload, $useCache, $app, &$job) {
            $item->expiresAfter($useCache ? 86400 : 40);
            $job = $this->scheduler->publish('integration:repo:sync', ['external_id' => $payload['external_ref'], 'app' => $app->getId()], $app->getId());
            $job = ['status' => 'new_repo', 'job' => $job->getId(), 'code' => 202];
            return [];
        });

        if ($job !== null && ($pkg = PacketonUtils::findPackagesByPayload($payload, $repo))) {
            $job2 = $this->scheduler->scheduleUpdate($pkg);
            $jobs = array_values(array_filter([$job['job'] ?? null, $job2->getId()]));
            $status = implode(',', array_filter([$job['status'] ?? null, 'success']));
            return ['status' => $status, 'code' => 202, 'job' => $jobs];
        }

        return $job;
    }

    /**
     * @param array{name: string, id: string, source_id: string, source_branch: string,
     *      target_id: string, target_branch: string, default_branch: string, iid: int|string} $payload
     * {@inheritdoc}
     */
    protected function pullRequestReview(App $app, array $payload, callable $request): ?array
    {
        $name = $payload['name'];
        $config = $this->getConfig();
        if (!AppUtils::enableReview($config, $app) || AppUtils::isRepoExcluded($app, $name)) {
            $repo = $this->registry->getRepository(Package::class);
            $package = PacketonUtils::findPackagesByPayload($payload, $repo);
            if (!$package?->isPullRequestReview()) {
                return [];
            }
        }

        $hasComposerLock = $this->getCached($app, "hasLocks:$name", callback: function (CacheItem $item) use ($app, $payload) {
            $item->expiresAfter(3600);
            $token = $this->refreshToken($app);
            return (bool)$this->getRemoteContent($token, $payload['id'], 'composer.lock', $payload['default_branch'] ?? null);
        });
        if (false === $hasComposerLock) {
            return [];
        }

        $token = $this->refreshToken($app);
        $newLock = $this->getRemoteContent($token, $payload['source_id'], 'composer.lock', $payload['source_branch']);
        $prevLock = $this->getRemoteContent($token, $payload['target_id'], 'composer.lock', $payload['target_branch']);
        if (null === ($diff = ComposerDiffReview::generateDiff($prevLock, $newLock))) {
            return [];
        }

        $iid = $payload['iid'];
        $setId = "pull_review:{$app->getId()}";
        $key = "repo:$name:$iid";

        $commentId = null;
        if (false === $this->redis->hSetNx($setId, $key, '0')) {
            $commentId = (int)$this->redis->hGet($setId, $key);
        }

        if ($commentId === null) {
            $review = $request("POST", $diff);
            $this->redis->hSet($setId, $key, (string)$review['id']);
            $this->redis->hSet($setId, "$key:diff", sha1($diff));
            return [];
        }
        if ($commentId > 0) {
            if (sha1($this->redis->hGet($setId, "$key:diff") ?: '') === sha1($diff)) {
                return [];
            }
            $this->redis->hSet($setId, "$key:diff", sha1($diff));
            $request("PUT", $diff, $commentId);
            return [];
        }
        return [];
    }

    protected function getRemoteContent(array $token, string|int $projectId, string $file, ?string $ref = null, bool $asJson = true): null|string|array
    {
        $url = str_replace(['{project_id}', '{file}', '{ref}'], [(string)$projectId, $file, $ref], $this->remoteContentUrl);
        if (empty($ref)) {
            $url = str_replace('?ref=', '', $url);
        }

        $isRaw = $this->remoteContentFormat === 'raw';
        try {
            $content = $this->makeApiRequest($token, 'GET', $url, [], !$isRaw);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$isRaw) {
            $content = base64_decode($content['content'] ?? '') ?: null;
        }

        $content = $asJson && $content ? json_decode($content, true) : $content;
        return $asJson ? (is_array($content) ? $content : null) : $content;
    }

    protected function makeApiRequest(array $token, string $method, string $path, array $params = [], bool $asJson = true): array|string
    {
        $params = array_merge_recursive($this->getApiHeaders($token), $params);
        $response = $this->httpClient->request($method, $this->getApiUrl($path), $params);
        $content = $response->getContent();
        if (false === $asJson) {
            return $content;
        }

        $content = $content  ? json_decode($content, true) : [];

        return is_array($content) ? $content : [];
    }

    protected function makeCGetRequest(array $token, string $path, array $params = []): array
    {
        $column = $params['column'] ?? null;
        unset($params['column']);

        $options = [];
        if ($name = ($this->paginatorQueryName ?? null)) {
            $options['query_name'] = $name;
        }

        $params = array_merge_recursive($this->getApiHeaders($token), $params);
        $paginator = new GithubResultPager($this->httpClient, $this->getApiUrl($path), $params, options: $options);
        return $paginator->all($column);
    }

    protected function getApiHeaders(array $token, array $default = []): array
    {
        $params = array_merge_recursive($this->config['http_options'] ?? [], [
            'headers' => [
                'Authorization' => "Bearer {$token['access_token']}",
            ]
        ]);

        $params['headers'] = array_merge($params['headers'], ['Accept' => 'application/json'], $default);
        return $params;
    }
}
