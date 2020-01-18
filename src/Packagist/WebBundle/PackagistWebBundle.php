<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packagist\WebBundle;

use Packagist\WebBundle\DependencyInjection\CompilerPass\ApiFirewallCompilerPass;
use Packagist\WebBundle\DependencyInjection\CompilerPass\WorkerLocatorPass;
use Packagist\WebBundle\DependencyInjection\Security\ApiHttpBasicFactory;
use Symfony\Bundle\SecurityBundle\DependencyInjection\SecurityExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackagistWebBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
        /** @var SecurityExtension $extension */
        $extension = $container->getExtension('security');
        $extension->addSecurityListenerFactory(new ApiHttpBasicFactory());

        $container->addCompilerPass(new WorkerLocatorPass());
        $container->addCompilerPass(new ApiFirewallCompilerPass());
    }
}
