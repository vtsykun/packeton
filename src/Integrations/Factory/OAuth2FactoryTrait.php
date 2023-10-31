<?php

declare(strict_types=1);

namespace Packeton\Integrations\Factory;

use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;

trait OAuth2FactoryTrait
{
    /**
     * {@inheritdoc}
     */
    public function create(ContainerBuilder $container, string $id, array $config): string
    {
        // listener
        $clientId = 'packeton_integration.'.$id;
        $container->setDefinition($clientId, new ChildDefinition($this->class))
            ->setArgument('$config', $config);

        return $clientId;
    }

    /**
     * {@inheritdoc}
     */
    public function addConfiguration(NodeDefinition $node): void
    {
        $builder = $node->children();

        $builder
            ->scalarNode('client_id')->end()
            ->scalarNode('client_secret')->end();
    }

    /**
     * {@inheritdoc}
     */
    public function getKey(): string
    {
        return $this->key;
    }
}
