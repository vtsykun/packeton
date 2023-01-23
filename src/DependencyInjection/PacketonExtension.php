<?php

declare(strict_types=1);

namespace Packeton\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
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
        if (true === $config['archive']) {
            $container->setParameter('packeton_archive_opts', $config['archive_options'] ?? []);
        }

        $container->setParameter('anonymous_access', $config['anonymous_access'] ?? false);
        $container->setParameter('anonymous_archive_access', $config['anonymous_archive_access'] ?? false);

        $container->setParameter('packeton_github_no_api', $config['github_no_api'] ?? false);

        $container->setParameter('packeton_jws_config', $config['jwt_authentication'] ?? []);
        $container->setParameter('packeton_jws_algo', $config['jwt_authentication']['algo'] ?? 'EdDSA');

        $container->setParameter('.packeton_repositories', $config['mirrors'] ?? []);
    }

    /**
     * @return string
     */
    public function getAlias(): string
    {
        return 'packeton';
    }
}
