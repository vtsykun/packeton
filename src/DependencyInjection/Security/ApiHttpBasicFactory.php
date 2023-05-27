<?php

namespace Packeton\DependencyInjection\Security;

use Packeton\Security\Api\ApiTokenAuthenticator;
use Symfony\Bundle\SecurityBundle\DependencyInjection\Security\Factory\AuthenticatorFactoryInterface;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class ApiHttpBasicFactory implements AuthenticatorFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function getKey(): string
    {
        return 'api-basic';
    }

    /**
     * {@inheritdoc}
     */
    public function addConfiguration(NodeDefinition $node): void
    {
        $node
            ->children()
                ->scalarNode('provider')->end()
            ->end()
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function createAuthenticator(ContainerBuilder $container, string $firewallName, array $config, string $userProviderId): string|array
    {
        $authenticatorId = 'packeton.security.authentication.' . $firewallName;

        $service = new ChildDefinition(ApiTokenAuthenticator::class);
        $service->setArgument('$userProvider', new Reference($userProviderId));

        $container
            ->setDefinition($authenticatorId, $service);

        return $authenticatorId;
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return 0;
    }
}
