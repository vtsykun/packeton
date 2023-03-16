<?php

declare(strict_types=1);

namespace Packeton\DependencyInjection\CompilerPass;

use Packeton\Package\UpdaterFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class UpdaterLocatorPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        $updaterReferences = [];
        $tagged = $container->findTaggedServiceIds('updater', true);
        foreach ($tagged as $serviceId => $tags) {
            $serviceDefinition = $container->getDefinition($serviceId);
            $updaterClass = $container->getParameterBag()->resolveValue($serviceDefinition->getClass());
            foreach ($updaterClass::supportRepoTypes() as $repoType) {
                $updaterReferences[$repoType] = new Reference($serviceId);
            }
        }

        $serviceLocator = ServiceLocatorTagPass::register($container, $updaterReferences);
        $container->getDefinition(UpdaterFactory::class)
            ->setArgument('$container', $serviceLocator);
    }
}
