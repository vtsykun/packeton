<?php

namespace Packeton;

use Packeton\DependencyInjection\CompilerPass\ApiFirewallCompilerPass;
use Packeton\DependencyInjection\CompilerPass\WorkerLocatorPass;
use Packeton\DependencyInjection\PacketonExtension;
use Packeton\DependencyInjection\Security\ApiHttpBasicFactory;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\DependencyInjection\SecurityExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

ini_set('date.timezone', 'UTC');

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function build(ContainerBuilder $container)
    {
        /** @var SecurityExtension $extension */
        $extension = $container->getExtension('security');
        $extension->addAuthenticatorFactory(new ApiHttpBasicFactory());

        $container->registerExtension(new PacketonExtension());

        $container->addCompilerPass(new ApiFirewallCompilerPass());
        $container->addCompilerPass(new WorkerLocatorPass());
    }
}
