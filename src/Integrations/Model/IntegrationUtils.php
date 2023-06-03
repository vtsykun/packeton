<?php

declare(strict_types=1);

namespace Packeton\Integrations\Model;

use Packeton\Entity\OAuthIntegration;
use Packeton\Integrations\AppInterface;

class IntegrationUtils
{
    public static $clonePref = [
        'api', 'clone_https', 'clone_ssh'
    ];

    public static function findUrl(string $externalId, OAuthIntegration $oauth, AppInterface $app): string
    {
        $repos = $app->repositories($oauth);

        $repos = array_filter($repos, fn($r) => $r['ext_ref'] === $externalId || $r['name'] === $externalId);
        if (!$repo = reset($repos)) {
            throw new \InvalidArgumentException(sprintf("Not possible found repository URL, looking in %s repos", count($repos)));
        }

        return self::clonePref($app->getConfig(), $oauth) === 'clone_ssh' && isset($repo['ssh_url']) ? $repo['ssh_url'] : $repo['url'];
    }

    public static function clonePref(AppConfig $config, OAuthIntegration $oauth): string
    {
        return $oauth->getClonePreference() ?: $config->clonePref();
    }

    public static function useApiPref(AppConfig $config, OAuthIntegration $oauth): bool
    {
        return !in_array(self::clonePref($config, $oauth), ['clone_ssh', 'clone_https']);
    }
}
