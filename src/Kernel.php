<?php

namespace Packeton;

use Packeton\DependencyInjection\CompilerPass\ApiFirewallCompilerPass;
use Packeton\DependencyInjection\CompilerPass\WorkerLocatorPass;
use Packeton\DependencyInjection\PacketonExtension;
use Packeton\DependencyInjection\Security\ApiHttpBasicFactory;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\DependencyInjection\SecurityExtension;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

ini_set('date.timezone', 'UTC');

class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        configureContainer as traitConfigureContainer;
        configureRoutes as traitConfigureRoutes;
    }

    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->traitConfigureContainer($container, $loader, $builder);

        $configDir = $this->getConfigDir();
        if (class_exists(WebProfilerBundle::class)) {
            $container->import($configDir.'/{packages}/withdev/*.yaml');
        }
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $this->traitConfigureRoutes($routes);

        $configDir = $this->getConfigDir();
        if (class_exists(WebProfilerBundle::class)) {
            $routes->import($configDir.'/{routes}/withdev/*.yaml');
        }
    }

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
