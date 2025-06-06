<?php

declare(strict_types=1);

namespace Packeton\Integrations\Keycloak;

use Packeton\Integrations\Factory\OAuth2FactoryInterface;
use Packeton\Integrations\Factory\OAuth2FactoryTrait;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class KeycloakOAuth2Factory implements OAuth2FactoryInterface
{
    protected $class = KeycloakIntegration::class;
    protected $key = 'keycloak';

    use OAuth2FactoryTrait;

    /**
     * {@inheritdoc}
     */
    public function addConfiguration(NodeDefinition $node): void
    {
        $builder = $node->children();

        $builder
            ->scalarNode('base_url')->end()
            ->scalarNode('realm')->end()
            ->scalarNode('client_id')->end()
            ->scalarNode('client_secret')->end();
    }
}