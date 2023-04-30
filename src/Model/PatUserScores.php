<?php

declare(strict_types=1);

namespace Packeton\Model;

class PatUserScores
{
    private static $scoresMap = [
        'metadata' => [
            'root_packages', 'root_providers', 'metadata_changes', 'root_package', 'root_package_v2', 'download_dist_package',
            'track_download', 'track_download_batch'
        ],
        'mirror:read' => ['mirror_root', 'mirror_metadata_v2', 'mirror_metadata_v1', 'mirror_zipball', 'mirror_provider_includes'],
        'mirror:all' => ['@mirror:read'],
        'webhooks' => ['generic_webhook_invoke', 'github_postreceive', 'bitbucket_postreceive', 'generic_postreceive', 'generic_named_postreceive'],
        'feeds' => ['feeds', 'feed_packages', 'feed_releases', 'feed_vendor', 'feed_package'],
        'packages:read' => ['api_packages_lists', 'api_packages_item', 'api_packages_changelog', 'list', 'package_changelog'],
        'packages:all' => ['@packages:read', 'api_edit_package', 'generic_create'],
        'users' => ['api_users_lists', 'api_users_get', 'api_users_create', 'api_users_update', 'api_users_delete'],
        'groups' => ['api_groups_lists', 'api_groups_create', 'api_groups_item', 'api_groups_update', 'api_groups_delete'],
    ];

    public static function getAllowedRoutes(string|array $scores): array
    {
        $scores = is_string($scores) ? [$scores] : $scores;
        $routes = [];
        foreach ($scores as $score) {
            $routes = array_merge($routes, self::$scoresMap[$score] ?? []);
        }

        foreach ($routes as $route) {
            if (str_starts_with($route, '@')) {
                $routes = array_merge($routes, self::getAllowedRoutes(substr($routes, 1)));
            }
        }

        return array_unique($routes);
    }
}
