<?php

declare(strict_types=1);

namespace Packeton\Integrations\Factory;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;

interface OAuth2FactoryInterface
{
    /**
     * Configures the container services for integration
     *
     * @param ContainerBuilder $container
     * @param string           $id                The unique id of the config
     * @param array            $config            The options array for the listener
     *
     * @return string. Service ID
     */
    public function create(ContainerBuilder $container, string $id, array $config): string;

    /**
     * Add configuration.
     *
     * @param NodeDefinition|ArrayNodeDefinition $node
     */
    public function addConfiguration(NodeDefinition $node): void;

    /**
     * Integration name, like: github, gitlab, gitea.
     *
     * @return string
     */
    public function getKey(): string;
}
