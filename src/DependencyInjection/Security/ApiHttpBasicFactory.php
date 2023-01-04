<?php

namespace Packeton\DependencyInjection\Security;

use Packeton\Security\Api\ApiTokenAuthenticator;
use Symfony\Bundle\SecurityBundle\DependencyInjection\Security\Factory\AuthenticatorFactoryInterface;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
    public function addConfiguration(NodeDefinition $builder)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function createAuthenticator(ContainerBuilder $container, string $firewallName, array $config, string $userProviderId): string|array
    {
        $authenticatorId = 'packeton.security.authentication.' . $firewallName;

        $container
            ->setDefinition($authenticatorId, new ChildDefinition(ApiTokenAuthenticator::class));

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
