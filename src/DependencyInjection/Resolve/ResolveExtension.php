<?php

declare(strict_types=1);

namespace Packeton\DependencyInjection\Resolve;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ExpressionLanguage;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class ResolveExtension extends Extension
{
    private ExpressionLanguage $expressionLanguage;

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        if (!$configs) {
            return;
        }

        $context = ['container' => $container];
        $configProcessor = function (array $configs) use (&$configProcessor, $context, $container) {
            foreach ($configs as $config) {
                if (isset($config['expression'])) {
                    $expression = $config['expression'];
                    unset($config['expression']);
                    if ($this->getExpressionLanguage()->evaluate($expression, $context)) {
                        $services = ['services' => $config['services'] ?? [], 'parameters' => $config['parameters'] ?? []];

                        if ($services = array_filter($services)) {
                            $this->loadDeferredServices($container, $services);
                        }

                        unset($services['services'], $services['parameters']);
                        foreach ($config as $name => $value) {
                            $container->prependExtensionConfig($name, $value);
                        }
                    }
                    continue;
                }

                if (is_array($config)) {
                    $configProcessor($config);
                    continue;
                }

                throw new \LogicException('Resolve node is require expression condition configuration');
            }
        };

        $configProcessor($configs);
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias(): string
    {
        return 'resolve';
    }

    private function loadDeferredServices(ContainerBuilder $container, array $content): void
    {
        $loader = \Closure::bind(static function() use ($container, $content) {
            $loader = new YamlFileLoader($container, new FileLocator(__DIR__));
            $loader->loadContent($content, __FILE__);
        }, null, YamlFileLoader::class);

        $loader();
    }

    private function getExpressionLanguage(): ExpressionLanguage
    {
        return $this->expressionLanguage ??= new ExpressionLanguage(getEnv: static fn (string $name) => $_ENV[strtoupper($name)] ?? null);
    }
}
