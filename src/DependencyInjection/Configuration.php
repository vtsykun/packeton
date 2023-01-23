<?php

namespace Packeton\DependencyInjection;

use Firebase\JWT\JWT;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('packeton');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->booleanNode('github_no_api')->end()
                ->scalarNode('rss_max_items')->defaultValue(40)->end()
                ->booleanNode('anonymous_access')->defaultFalse()->end()
                ->booleanNode('anonymous_archive_access')->defaultFalse()->end()
                ->booleanNode('archive')
                    ->defaultFalse()
                ->end()
                ->arrayNode('jwt_authentication')
                    ->children()
                        ->enumNode('algo')
                            ->info("Sign algo, default EdDSA libsodium")
                            ->defaultNull()
                            ->values(\array_keys(JWT::$supported_algs))
                        ->end()
                        ->scalarNode('private_key')->cannotBeEmpty()->end()
                        ->scalarNode('public_key')->cannotBeEmpty()->end()
                        ->booleanNode('passphrase')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('archive_options')
                    ->children()
                        ->scalarNode('format')->defaultValue('zip')->end()
                        ->scalarNode('basedir')->cannotBeEmpty()->end()
                        ->scalarNode('endpoint')->defaultNull()->end()
                        ->booleanNode('include_archive_checksum')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end();

        $rootNode
            ->validate()
            ->always(function ($values) {
                if (($values['archive'] ?? false) && !isset($values['archive_options'])) {
                    throw new \InvalidArgumentException('archive_options is required if archive: true');
                }

                return $values;
            })->end();

        $this->addMirrorsRepositoriesConfiguration($rootNode);

        return $treeBuilder;
    }

    private function addMirrorsRepositoriesConfiguration(ArrayNodeDefinition|NodeDefinition $node)
    {
        /** @var ArrayNodeDefinition $mirrorNodeBuilder */
        $mirrorNodeBuilder = $node
            ->children()
                ->arrayNode('mirrors')
                    ->useAttributeAsKey('name')
                    ->prototype('array');

        $mirrorNodeBuilder
            ->children()
                ->scalarNode('url')->end()
                ->variableNode('repository_json')
                    ->beforeNormalization()
                    ->always()
                    ->then(static function ($repository) {
                        if (\is_string($repository) && \is_array($repo = @json_decode($repository, true))) {
                            return $repo;
                        }
                        if (\is_string($repository) && \is_file($repository)) {
                            $repository = \file_get_contents($repository);
                            $repository = $repository ? \json_decode($repository, true) : null;
                        }
                        return $repository;
                    })
                    ->end()
                ->end()
                ->scalarNode('sync_interval')->defaultValue(86400)->end()
                ->booleanNode('sync_lazy')->end()
                ->booleanNode('enable_dist_mirror')->defaultTrue()->end()
                ->booleanNode('parent_notify')->end()
                ->booleanNode('disable_v1')->end()
                ->scalarNode('info_cmd_message')->end()
                ->scalarNode('logo')->end()
                ->arrayNode('available_package_patterns')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('available_packages')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('chain_providers')
                    ->scalarPrototype()->end()
                ->end()
            ->end()
        ;

        $defaultLogos = [
            'asset-packagist.org' => '/packeton/img/logo/asset-packagist.svg',
            'packages.drupal.org' => '/packeton/img/logo/drupl.png',
            'repo.packagist.org' => '/packeton/img/logo/packagist.png',
            'packagist.org' => '/packeton/img/logo/packagist.png',
            'wpackagist.org' => '/packeton/img/logo/wordpress.png',
            'repo.magento.com' => '/packeton/img/logo/magento.png',
            'satis.oroinc.com' => '/packeton/img/logo/orocrm.png',
            'packagist.oroinc.com' => '/packeton/img/logo/orocrm.png',
        ];

        $mirrorNodeBuilder
            ->beforeNormalization()
                ->always()
                ->then(static function ($provider) use ($defaultLogos) {
                    if (!isset($provider['url'])) {
                        return $provider;
                    }

                    $host = \parse_url($provider['url'], \PHP_URL_HOST);

                    $provider['url'] = \rtrim($provider['url'], '/');
                    // packagist.org is mark lazy by default.
                    $isOfficial = \in_array($host, ['packagist.org','repo.packagist.org']);
                    if ($isOfficial && !isset($provider['sync_lazy'])) {
                        // packagist.org is very big and have v2, sync on fly by default.
                        $provider['sync_lazy'] = true;
                    }
                    if (!$isOfficial && !isset($provider['parent_notify'])) {
                        // Disable download stats for non packagist.org, by default
                        $provider['parent_notify'] = false;
                    }
                    if (!\array_key_exists('logo', $provider) && isset($defaultLogos[$host])) {
                        $provider['logo'] = $defaultLogos[$host];
                    }

                    return $provider;
                })
            ->end();
    }
}
