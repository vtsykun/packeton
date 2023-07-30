<?php

declare(strict_types=1);

namespace Packeton\DependencyInjection\Resolve;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ExpressionLanguage;
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
            foreach ($configs as $i => $config) {
                if (isset($config['expression'])) {
                    $expression = $config['expression'];
                    unset($config['expression']);
                    if ($this->getExpressionLanguage()->evaluate($expression, $context)) {
                        $services = ['services' => $config['services'] ?? [], 'parameters' => $config['parameters'] ?? []];

                        if ($services = array_filter($services)) {
                            // todo process services
                        }

                        unset($services['services'], $services['parameters']);
                        foreach ($config as $name => $value) {
                            $container->prependExtensionConfig($name, $value);
                        }
                    }
                    return;
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

    private function getExpressionLanguage(): ExpressionLanguage
    {
        return $this->expressionLanguage ??= new ExpressionLanguage(getEnv: function (string $name) {
            return $_ENV[strtoupper($name)] ?? null;
        });
    }
}
