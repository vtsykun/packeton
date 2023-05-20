<?php

declare(strict_types=1);

namespace Packeton\DependencyInjection;

use Packeton\Attribute\AsWorker;
use Packeton\Package\InMemoryDumper;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class PacketonExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('packagist_web.rss_max_items', $config['rss_max_items']);
        $container->setParameter('packeton_archive_all_opts', $config['archive_options'] ?? []);
        $container->setParameter('packeton_archive_opts', true === $config['archive'] ? $container->getParameter('packeton_archive_all_opts') : []);

        $container->setParameter('packeton_dumper_opts', $config['metadata'] ?? []);

        $hasPublicMirror = array_filter($config['mirrors'] ?? [] , fn ($i) => $i['public_access'] ?? false);
        $container->setParameter('anonymous_mirror_access', (bool) $hasPublicMirror);

        $container->setParameter('anonymous_access', $config['anonymous_access'] ?? false);
        $container->setParameter('anonymous_archive_access', $config['anonymous_archive_access'] ?? false);

        $container->setParameter('packeton_github_no_api', $config['github_no_api'] ?? false);

        $container->setParameter('packeton_jws_config', $config['jwt_authentication'] ?? []);
        $container->setParameter('packeton_jws_algo', $config['jwt_authentication']['algo'] ?? 'EdDSA');

        $container->setParameter('.packeton_repositories', $config['mirrors'] ?? []);
        $container->setParameter('packeton_artifact_paths', $config['allowed_artifact_paths'] ?? []);
        $container->setParameter('packeton_artifact_storage', $config['artifact_storage'] ?? null);

        $container->registerAttributeForAutoconfiguration(AsWorker::class, static function (ChildDefinition $definition, AsWorker $attribute) {
            $attributes = get_object_vars($attribute);
            $definition->addTag('queue_worker', $attributes);
        });
    }

    /**
     * @return string
     */
    public function getAlias(): string
    {
        return 'packeton';
    }
}
