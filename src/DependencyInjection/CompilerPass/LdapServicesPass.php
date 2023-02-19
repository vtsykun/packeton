<?php

declare(strict_types=1);

namespace Packeton\DependencyInjection\CompilerPass;

use Packeton\Security\CheckLdapCredentialsListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class LdapServicesPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        $serviceId = 'security.listener.form_login_ldap.main';
        if (!$container->hasDefinition($serviceId)) {
            return;
        }

        $container->getDefinition($serviceId)->setClass(CheckLdapCredentialsListener::class);
    }
}
