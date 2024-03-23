<?php

declare(strict_types=1);

namespace Packeton\Integrations\Model;

use Packeton\Entity\Job;
use Packeton\Entity\OAuthIntegration as App;
use Packeton\Integrations\AppInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class AppUtils
{
    public static $clonePref = [
        'api', 'clone_https', 'clone_ssh'
    ];

    public static function findUrl(string $externalId, App $app, AppInterface $client, ?AppConfig $config = null): string
    {
        $repos = $client->repositories($app);

        $config ??= $client->getConfig();
        $repos = array_filter($repos, fn($r) => $r['ext_ref'] === $externalId || $r['name'] === $externalId);
        if (!$repo = reset($repos)) {
            throw new \InvalidArgumentException(sprintf("Not possible found repository URL, looking in %s repos", count($repos)));
        }

        return self::clonePref($config, $app) === 'clone_ssh' && isset($repo['ssh_url']) ? $repo['ssh_url'] : $repo['url'];
    }

    public static function createLogJob(Request $request, App $app): Job
    {
        $job = new Job();
        $job->setType('webhook:integration');
        $job->setPackageId($app->getId());
        $headers = [];

        foreach ($request->headers->all() as $name => $value) {
            if (is_array($value)) {
                $value = reset($value);
            }

            if (is_scalar($value)) {
                $headers[] = "$name: $value";
            }
        }

        $result = [
            'request_headers' =>  implode("\n", $headers),
            'request_body' => substr($request->getContent(),0, 65536),
            'status' => Job::STATUS_COMPLETED,
        ];

        $job->start();
        $job->complete($result);
        $job->setResult($result);
        return $job;
    }

    public static function castError(\Throwable $e, App|array|null $app = null, bool $moreInfo = false): string
    {
        $msg = '';
        $app = $app instanceof App ? $app->getAccessToken() : $app;
        if ($e instanceof HttpExceptionInterface) {
            try {
                $msg = $e->getResponse()->getContent(false);
                $msg = trim(substr($msg, 0, 1024));
            } catch (\Throwable) {
            }
        }

        if (empty($msg)) {
            $msg = $e->getMessage();
        } else if (true === $moreInfo) {
            $msg = $e->getMessage() . " \n" . $msg;
        }

        if (isset($app['access_token']) && is_string($app['access_token'])) {
            $msg = str_replace($app['access_token'], '***', $msg);
        }
        if (isset($app['refresh_token']) && is_string($app['refresh_token'])) {
            $msg = str_replace($app['refresh_token'], '***', $msg);
        }

        $msg = preg_replace('/client_id=(\w+)/i', 'client_id=***', $msg);
        $msg = preg_replace('/client_secret=(\w+)/i', 'client_secret=***', $msg);
        return preg_replace('/([a-f0-9]{30,})/i', '****', $msg);
    }

    public static function clonePref(AppConfig $config, App $oauth): string
    {
        return $oauth->getClonePreference() ?: $config->clonePref();
    }

    public static function enableSync(AppConfig $config, App $oauth): bool
    {
        return $oauth->isEnableSynchronization() !== null ? $oauth->isEnableSynchronization() : $config->enableSync();
    }

    public static function enableReview(AppConfig $config, App $oauth): bool
    {
        return $oauth->isPullRequestReview() !== null ? $oauth->isPullRequestReview() : $config->isPullRequestReview();
    }

    public static function isRepoExcluded(App $app, ?string $path, ?array $orgs = null, ?string $fullPathColumn = 'identifier'): bool
    {
        if (null === $path) {
            return true;
        }

        if (!$app->filteredRepos([['name' => $path]])) {
            return true;
        }
        if (null === $orgs) {
            return false;
        }

        $fullPathColumn ??= 'identifier';
        [$namespace] = self::parseNamespace($path);
        $belong = array_filter($orgs, fn($org) => ($org[$fullPathColumn] ?? null) === $namespace);
        $belong = reset($belong) ?: [];

        return !$app->isConnected($belong['identifier'] ?? '@self');
    }

    public static function parseNamespace(string $path): array
    {
        $paths = explode('/', $path);
        if (count($paths) === 1) {
            return [$path, null];
        }

        $name = array_pop($paths);
        $namespace = implode('/', $paths);
        return [$namespace, $name];
    }

    public static function useApiPref(AppConfig $config, App $oauth): bool
    {
        return !in_array(self::clonePref($config, $oauth), ['clone_ssh', 'clone_https']);
    }
}
