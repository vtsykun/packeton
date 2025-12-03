<?php

declare(strict_types=1);

namespace Packeton\Integrations\Oidc;

use Packeton\Integrations\Factory\OAuth2FactoryInterface;
use Packeton\Integrations\Factory\OAuth2FactoryTrait;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class OidcOAuth2Factory implements OAuth2FactoryInterface
{
    protected $class = OidcOAuth2Login::class;
    protected $key = 'oidc';

    use OAuth2FactoryTrait;

    public function addConfiguration(NodeDefinition $node): void
    {
        $builder = $node->children();

        $builder
            ->scalarNode('client_id')
                ->isRequired()
                ->cannotBeEmpty()
            ->end()
            ->scalarNode('client_secret')
                ->isRequired()
                ->cannotBeEmpty()
            ->end()
            ->scalarNode('issuer')
                ->info('OIDC issuer URL (e.g., https://auth.example.com/application/o/packeton/)')
            ->end()
            ->scalarNode('discovery_url')
                ->info('Explicit OIDC discovery URL (.well-known/openid-configuration). If not set, derived from issuer.')
            ->end()
            ->arrayNode('scopes')
                ->defaultValue(['openid', 'email', 'profile'])
                ->scalarPrototype()->end()
            ->end()
            ->booleanNode('require_email_verified')
                ->defaultTrue()
                ->info('Reject login if email_verified claim is false')
            ->end()
            ->arrayNode('claim_mapping')
                ->addDefaultsIfNotSet()
                ->info('Map OIDC claims to user fields')
                ->children()
                    ->scalarNode('email')->defaultValue('email')->end()
                    ->scalarNode('username')->defaultValue('preferred_username')->end()
                    ->scalarNode('sub')->defaultValue('sub')->end()
                    ->scalarNode('roles_claim')
                        ->defaultNull()
                        ->info('Claim containing user roles/groups (e.g., groups, roles)')
                    ->end()
                    ->arrayNode('roles_map')
                        ->useAttributeAsKey('group', false)
                        ->normalizeKeys(false)
                        ->arrayPrototype()
                            ->scalarPrototype()->end()
                        ->end()
                        ->info('Map OIDC groups to Packeton roles')
                    ->end()
                ->end()
            ->end();
    }
}
