<?php

declare(strict_types=1);

namespace Packeton\DependencyInjection;

use Packeton\Attribute\AsIntegration;
use Packeton\Attribute\AsWorker;
use Packeton\Integrations\Bitbucket\BitbucketOAuth2Factory;
use Packeton\Integrations\Factory\OAuth2Factory;
use Packeton\Integrations\Factory\OAuth2FactoryInterface;
use Packeton\Integrations\Gitea\GiteaOAuth2Factory;
use Packeton\Integrations\Github\GithubAppFactory;
use Packeton\Integrations\Github\GithubOAuth2Factory;
use Packeton\Integrations\Gitlab\GitLabOAuth2Factory;
use Packeton\Integrations\IntegrationInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class PacketonExtension extends Extension implements PrependExtensionInterface
{
    /** @var OAuth2FactoryInterface[]  */
    protected $factories = [];

    protected ?ContainerBuilder $mergeContainer = null;

    public function __construct()
    {
        $this->addFactories(new GithubOAuth2Factory());
        $this->addFactories(new GitLabOAuth2Factory());
        $this->addFactories(new GithubAppFactory());
        $this->addFactories(new GiteaOAuth2Factory());
        $this->addFactories(new BitbucketOAuth2Factory());
    }

    public function addFactories(OAuth2FactoryInterface $factory): void
    {
        $this->factories[] = $factory;
    }

    /**
     * @return OAuth2FactoryInterface[]
     */
    public function getFactories(): array
    {
        return $this->factories;
    }

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $this->loadAutoconfigureFactories();
        $configuration = new Configuration($this->factories);

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
        $container->setParameter('.packeton_integrations', $config['integrations'] ?? []);

        $container->setParameter('packeton_artifact_paths', $config['artifacts']['allowed_paths'] ?? []);
        $container->setParameter('packeton_artifact_storage', $config['artifacts']['artifact_storage'] ?? null);
        $container->setParameter('packeton_artifact_types', $config['artifacts']['support_types'] ?? []);
        $container->setParameter('packeton_web_protection', $config['web_protection'] ?? null);
        $container->setParameter('packeton_health_check', $config['health_check'] ?? true);
        $container->setParameter('packeton_max_import', $config['max_import'] ?? null);

        $container->registerAttributeForAutoconfiguration(AsWorker::class, static function (ChildDefinition $definition, AsWorker $attribute) {
            $attributes = get_object_vars($attribute);
            $definition->addTag('queue_worker', $attributes);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias(): string
    {
        return 'packeton';
    }

    /**
     * {@inheritdoc}
     */
    public function prepend(ContainerBuilder $container): void
    {
        $this->mergeContainer = $container;
    }

    protected function loadAutoconfigureFactories(): void
    {
        if (null === $container = $this->mergeContainer) {
            return;
        }

        $definitions = array_keys($container->getDefinitions());
        $definitions = array_filter($definitions, static fn ($name) => str_starts_with((string)$name, 'Packeton\\') && class_exists($name) && is_subclass_of($name, IntegrationInterface::class));
        foreach ($definitions as $className) {
            /** @var AsIntegration $attribute */
            if (!$attribute = ((new \ReflectionClass($className))->getAttributes(AsIntegration::class)[0] ?? null)?->newInstance()) {
                continue;
            }

            if (class_exists($alias = $attribute->nameOrClass)) {
                $this->addFactories(new $alias);
            } else {
                $this->addFactories(new OAuth2Factory($alias, $className));
            }
        }
    }
}
