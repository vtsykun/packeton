<?php

namespace Packagist\WebBundle\DependencyInjection\Security;

use Symfony\Bundle\SecurityBundle\DependencyInjection\Security\Factory\SecurityFactoryInterface;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Reference;

class ApiHttpBasicFactory implements SecurityFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function create(ContainerBuilder $container, $id, $config, $userProvider, $defaultEntryPoint)
    {
        $provider = 'packagist.security.authentication.provider.'.$id;
        $container
            ->setDefinition($provider, new DefinitionDecorator('packagist.security.authentication.provider'));

        // entry point
        $entryPointId = $this->createEntryPoint($container, $id, $config, $defaultEntryPoint);

        // listener
        $listenerId = 'packagist.security.authentication.listener.'.$id;
        $listener = $container->setDefinition($listenerId, new DefinitionDecorator('packagist.security.authentication.listener'));
        $listener->replaceArgument(2, $id)
            ->replaceArgument(3, new Reference($entryPointId));

        return [$provider, $listenerId, $entryPointId];
    }

    protected function createEntryPoint(ContainerBuilder $container, $id, $config, $defaultEntryPoint)
    {
        if (null !== $defaultEntryPoint) {
            return $defaultEntryPoint;
        }

        $entryPointId = 'packagist.authentication.entry_point.'.$id;
        $container
            ->setDefinition($entryPointId, new DefinitionDecorator('packagist.authentication.entry_point'));

        return $entryPointId;
    }

    /**
     * {@inheritdoc}
     */
    public function getPosition()
    {
        return 'http';
    }

    /**
     * {@inheritdoc}
     */
    public function getKey()
    {
        return 'api-basic';
    }

    public function addConfiguration(NodeDefinition $builder)
    {
    }
}
