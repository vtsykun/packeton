<?php

declare(strict_types=1);

namespace Packeton\Integrations\Gitea;

use Packeton\Integrations\Factory\OAuth2FactoryInterface;
use Packeton\Integrations\Factory\OAuth2FactoryTrait;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class GiteaOAuth2Factory implements OAuth2FactoryInterface
{
    protected $class = GiteaIntegration::class;
    protected $key = 'gitea';

    use OAuth2FactoryTrait;

    /**
     * {@inheritdoc}
     */
    public function addConfiguration(NodeDefinition $node): void
    {
        $builder = $node->children();

        $builder
            ->scalarNode('client_id')->end()
            ->scalarNode('client_secret')->end()
            ->scalarNode('api_version')->example('v1')->end();

    }
}
