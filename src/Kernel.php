<?php

namespace Packeton;

use Packeton\DBAL\OpensslCrypter;
use Packeton\DBAL\Types\EncryptedArrayType;
use Packeton\DBAL\Types\EncryptedTextType;
use Packeton\DependencyInjection\CompilerPass\ApiFirewallCompilerPass;
use Packeton\DependencyInjection\CompilerPass\LdapServicesPass;
use Packeton\DependencyInjection\CompilerPass\MirrorsConfigCompilerPass;
use Packeton\DependencyInjection\CompilerPass\UpdaterLocatorPass;
use Packeton\DependencyInjection\CompilerPass\WorkerLocatorPass;
use Packeton\DependencyInjection\PacketonExtension;
use Packeton\DependencyInjection\Security\ApiHttpBasicFactory;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\DependencyInjection\SecurityExtension;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\ErrorHandler\DebugClassLoader;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

ini_set('date.timezone', 'UTC');

class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        configureRoutes as traitConfigureRoutes;
    }

    public function __construct(string $environment, bool $debug)
    {
        if (\class_exists(DebugClassLoader::class)) {
            DebugClassLoader::disable();
        }

        parent::__construct($environment, $debug);
    }

    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $configDir = $this->getConfigDir();

        if (is_file($configDir.'/services.yaml')) {
            $container->import($configDir.'/services.yaml');
            $container->import($configDir.'/{services}_'.$this->environment.'.yaml');
        } else {
            $container->import($configDir.'/{services}.php');
        }

        $container->import($configDir.'/{packages}/*.yaml');
        $container->import($configDir.'/{packages}/'.$this->environment.'/*.yaml');

        if (class_exists(WebProfilerBundle::class) && $this->environment !== 'test') {
            $container->import($configDir.'/{packages}/withdev/*.yaml');
        }
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $this->traitConfigureRoutes($routes);

        $configDir = $this->getConfigDir();
        if (class_exists(WebProfilerBundle::class) && $this->environment !== 'test') {
            $routes->import($configDir.'/{routes}/withdev/*.yaml');
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        /** @var SecurityExtension $extension */
        $extension = $container->getExtension('security');
        $extension->addAuthenticatorFactory(new ApiHttpBasicFactory());

        $container->registerExtension(new PacketonExtension());

        $container->addCompilerPass(new LdapServicesPass());
        $container->addCompilerPass(new ApiFirewallCompilerPass());
        $container->addCompilerPass(new WorkerLocatorPass());
        $container->addCompilerPass(new UpdaterLocatorPass());
        $container->addCompilerPass(new MirrorsConfigCompilerPass());
    }

    /**
     * {@inheritdoc}
     */
    public function boot(): void
    {
        parent::boot();

        // class Type has a final constructor, inject dependencies on the boot
        $crypter = $this->container->get(OpensslCrypter::class);

        EncryptedTextType::setCrypter($crypter);
        EncryptedArrayType::setCrypter($crypter);

        if (class_exists(DebugClassLoader::class)) {
            DebugClassLoader::disable();;
        }
    }
}
