<?php

declare(strict_types=1);

namespace Packeton\Integrations\Bitbucket;

use Packeton\Integrations\Factory\OAuth2FactoryInterface;
use Packeton\Integrations\Factory\OAuth2FactoryTrait;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class BitbucketOAuth2Factory implements OAuth2FactoryInterface
{
    protected $class = BitbucketIntegration::class;
    protected $key = 'bitbucket';

    use OAuth2FactoryTrait;

    /**
     * {@inheritdoc}
     */
    public function addConfiguration(NodeDefinition $node): void
    {
        $builder = $node->children();

        $builder
            ->scalarNode('key')->end()
            ->scalarNode('secret')->end()
            ->scalarNode('api_version')->example('2.0')->end();
    }
}
