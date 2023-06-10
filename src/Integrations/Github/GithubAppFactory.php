<?php

declare(strict_types=1);

namespace Packeton\Integrations\Github;

use Packeton\Integrations\Factory\OAuth2FactoryInterface;
use Packeton\Integrations\Factory\OAuth2FactoryTrait;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class GithubAppFactory implements OAuth2FactoryInterface
{
    protected $class = GitHubAppIntegration::class;
    protected $key = 'githubapp';

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
            ->scalarNode('private_key')->isRequired()->end()
            ->scalarNode('passphrase')->end()
            ->integerNode('app_id')->isRequired()->end();
    }
}
