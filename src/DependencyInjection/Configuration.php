<?php

namespace Packeton\DependencyInjection;

use Firebase\JWT\JWT;
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
                            ->values(array_keys(JWT::$supported_algs))
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

        return $treeBuilder;
    }
}
