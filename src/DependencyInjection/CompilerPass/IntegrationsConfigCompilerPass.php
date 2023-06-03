<?php

declare(strict_types=1);

namespace Packeton\DependencyInjection\CompilerPass;

use Packeton\DependencyInjection\PacketonExtension;
use Packeton\Integrations\Factory\OAuth2FactoryInterface;
use Packeton\Integrations\IntegrationRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class IntegrationsConfigCompilerPass implements CompilerPassInterface
{
    public function __construct(protected PacketonExtension $extension)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        $integrations = $container->getParameter('.packeton_integrations') ?: [];
        $container->getParameterBag()->remove('.packeton_integrations');

        $this->createIntegrationRegistry($integrations, $this->extension->getFactories(), $container);
    }

    /**
     * @param array $configs
     * @param OAuth2FactoryInterface[] $factories
     * @param ContainerBuilder $container
     * @return void
     */
    private function createIntegrationRegistry(array $configs, array $factories, ContainerBuilder $container): void
    {
        $managers = $loginProviders = [];
        foreach ($configs as $id => $config) {
            foreach ($factories as $factory) {
                $key = str_replace('-', '_', $factory->getKey());
                if (isset($config[$key])) {
                    $config = array_merge($config[$key], $config);
                    unset($config[$key]);
                    $config['name'] = $id;
                    $definitionId = $factory->create($container, $id, $config);
                    $managers[$id] = new Reference($definitionId);

                    if ($data = $this->useForLoginCheck($config)) {
                        $loginProviders[$id] = $data;
                    }
                }
            }
        }

        $serviceLocator = ServiceLocatorTagPass::register($container, $managers);

        $container->getDefinition(IntegrationRegistry::class)
            ->setArgument(0, $serviceLocator)
            ->setArgument(1, array_keys($managers))
            ->setArgument(2, $loginProviders);
    }

    private function useForLoginCheck(array $config): ?array
    {
        if (!($config['oauth2_login'] ?? false) || !($config['enabled'] ?? true)) {
            return null;
        }

        return [
            'name' => $config['name'],
            'logo' => $config['svg_logo'] ?? null,
            'title' => $config['login_title'] ?? $config['name'],
        ];
    }
}
