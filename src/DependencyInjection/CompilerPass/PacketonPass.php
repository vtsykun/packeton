<?php

declare(strict_types=1);

namespace Packeton\DependencyInjection\CompilerPass;

use Packeton\Package\UpdaterFactory;
use Packeton\Service\QueueWorker;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class PacketonPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->registerUpdaters($container);
        $this->registerWorkers($container);

        if ($container->hasDefinition('snc_redis.default')) {
            $container->getDefinition('snc_redis.default')->setLazy(true);
        }
    }

    private function registerUpdaters(ContainerBuilder $container): void
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

    private function registerWorkers(ContainerBuilder $container): void
    {
        $workersReferences = [];
        $tagged = $container->findTaggedServiceIds('queue_worker', true);
        foreach ($tagged as $id => $tags) {
            foreach ($tags as $tag) {
                if (!isset($tag['topic'])) {
                    throw new \LogicException('Topic is required for tag queue_worker');
                }
                $workersReferences[$tag['topic']] = new Reference($id);
            }
        }
        $workersServiceLocator = ServiceLocatorTagPass::register($container, $workersReferences);

        $container->getDefinition(QueueWorker::class)
            ->setArgument('$workersContainer', $workersServiceLocator);
    }
}
