<?php

declare(strict_types=1);

namespace Packeton\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures API security firewalls to be able to work in two modes: stateless and statefull (with session and api_key).
 */
final class ApiFirewallCompilerPass implements CompilerPassInterface
{
    /** @var array */
    private $contextListeners = [];

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        $securityConfigs = $container->getExtensionConfig('security');
        if (empty($securityConfigs[0]['firewalls'])) {
            return;
        }

        foreach ($securityConfigs[0]['firewalls'] as $name => $config) {
            if ($this->isStatelessFirewallWithContext($config)) {
                $this->configureStatelessFirewallWithContext($container, $name, $config);
            }
        }
    }

    /**
     * Checks whether a firewall is stateless and have context parameter
     *
     * @param array $firewallConfig
     *
     * @return bool
     */
    private function isStatelessFirewallWithContext(array $firewallConfig): bool
    {
        return ($firewallConfig['stateless'] ?? null) && ($firewallConfig['context'] ?? null);
    }

    /**
     * @param ContainerBuilder $container
     * @param string           $firewallName
     * @param array            $firewallConfig
     */
    private function configureStatelessFirewallWithContext(
        ContainerBuilder $container,
        string $firewallName,
        array $firewallConfig
    ): void {
        $contextId = 'security.firewall.map.context.' . $firewallName;
        if (!$container->hasDefinition($contextId)) {
            return;
        }

        $contextDef = $container->getDefinition($contextId);
        $contextKey = $firewallConfig['context'];

        // add the context listener
        $listenerId = $this->createContextListener($container, $contextKey);
        /** @var IteratorArgument $listeners */
        $listeners = $contextDef->getArgument(0);
        $contextListeners = array_merge([new Reference($listenerId)], $listeners->getValues());

        $contextDef->replaceArgument(0, $contextListeners);
    }

    /**
     * @param ContainerBuilder $container
     * @param string           $contextKey
     *
     * @return string
     */
    private function createContextListener(ContainerBuilder $container, $contextKey): string
    {
        if (isset($this->contextListeners[$contextKey])) {
            return $this->contextListeners[$contextKey];
        }

        $listenerId = 'packagist.context_listener.' . $contextKey;
        $container
            ->setDefinition($listenerId, new ChildDefinition('security.context_listener'))
            ->replaceArgument(2, $contextKey)
            ->replaceArgument(4, null); // Remove event dispatcher to prevent save session for stateless api.

        $this->contextListeners[$contextKey] = $listenerId;

        return $listenerId;
    }
}
