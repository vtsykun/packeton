<?php

declare(strict_types=1);

namespace Packagist\WebBundle\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class WorkerLocatorPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        $workersReferences = [];
        $tagged = $container->findTaggedServiceIds('queue_worker', true);
        foreach ($tagged as $id => $tags) {
            foreach ($tags as $tag) {
                if (!isset($tag['topic'])) {
                    throw new \LogicException('Topic is required for tag queue_worker');
                }
                $workersReferences[$tag['topic']] = new Reference($id);;
            }
        }
        $workersServiceLocator = ServiceLocatorTagPass::register($container, $workersReferences);

        $container->getDefinition('packagist.queue_worker')
            ->replaceArgument(5, $workersServiceLocator);
    }
}
