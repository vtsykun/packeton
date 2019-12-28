<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PlaceholderExtension extends AbstractExtension
{
    public const VARIABLE_NAME = '__url_placeholder_context';

    /**
     * {@inheritdoc}
     */
    public function getTokenParsers()
    {
        return [
            new PlaceholderTokenParser()
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getFunctions()
    {
        return [
            new TwigFunction(
                'placeholder',
                [$this, 'fillPlaceholder']
            )
        ];
    }

    public function fillPlaceholder(string $name, $variables, PlaceholderContext $context = null)
    {
        if ($context === null) {
            return;
        }

        if (is_string($variables)) {
            $variables = [$variables];
        }

        if (is_array($variables)) {
            foreach ($variables as &$var) {
                if (!is_scalar($var)) {
                    throw new \RuntimeException(sprintf('Placeholder variable "%s" must be scalar or scalar[]', $name));
                }

                $var = (string)$var;
            }

            $context->setPlaceholder($name, $variables);
            return;
        }

        throw new \RuntimeException(sprintf('Placeholder variable "%s" must be scalar or scalar[]', $name));
    }
}
