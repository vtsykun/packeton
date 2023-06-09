<?php

declare(strict_types=1);

namespace Packeton\Integrations\Gitlab;

use Packeton\Integrations\Factory\OAuth2FactoryInterface;
use Packeton\Integrations\Factory\OAuth2FactoryTrait;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class GitLabOAuth2Factory implements OAuth2FactoryInterface
{
    protected $class = GitLabIntegration::class;
    protected $key = 'gitlab';

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
            ->scalarNode('api_version')->example('v4')->end();

    }
}
