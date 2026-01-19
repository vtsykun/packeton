<?php

declare(strict_types=1);

namespace Packeton\DependencyInjection\CompilerPass;

use Packeton\Mirror\RemoteProxyRepository;
use Packeton\Mirror\ProxyRepositoryRegistry;
use Packeton\Mirror\Service\RemotePackagesManager;
use Packeton\Mirror\Service\ZipballDownloadManager;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class MirrorsConfigCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $references = [];
        $repositories = $container->getParameter('.packeton_repositories') ?: [];
        foreach ($repositories as $name => $repoConfig) {
            $serviceId = $this->createMirrorRepo($container, $repoConfig, $name);
            $references[$name] = new Reference($serviceId);
        }

        $repoLocator = ServiceLocatorTagPass::register($container, $references);

        $container->getDefinition(ProxyRepositoryRegistry::class)
            ->setArgument('$container', $repoLocator)
            ->setArgument('$repos', array_keys($repositories));

        $container->getParameterBag()->remove('.packeton_repositories');
    }

    private function createMirrorRepo(ContainerBuilder $container, array $repoConfig, string $name): string
    {
        $rmp = new ChildDefinition(RemotePackagesManager::class);
        $rmp->setArgument('$repo', $name);
        $container->setDefinition($rmpId = 'packeton.mirror_rmp.' . $name, $rmp);

        $container->setDefinition($dmId = 'packeton.mirror_dm.' . $name, new ChildDefinition(ZipballDownloadManager::class))
            ->setArgument('$aliasName', $name)
            ->setArgument('$mirrorDistStorage', new Reference('flysystem.mirror.dist'))
            ->setArgument('$mirrorDistCacheDir', '%mirror_dist_cache_dir%');

        $service = new ChildDefinition(RemoteProxyRepository::class);

        $service->setArgument('$repoConfig', ['name' => $name, 'type' => 'composer'] + $repoConfig)
            ->setArgument('$rpm', new Reference($rmpId))
            ->setArgument('$zipballManager', new Reference($dmId))
            ->setArgument('$mirrorMetaStorage', new Reference('flysystem.mirror.meta'))
            ->setArgument('$mirrorMetaCacheDir', '%mirror_meta_cache_dir%');

        $container
            ->setDefinition($serviceId = $this->getMirrorServiceId($name), $service);

        return $serviceId;
    }

    private function getMirrorServiceId(string $name): string
    {
        return 'packeton.mirror_repository.' . $name;
    }
}
