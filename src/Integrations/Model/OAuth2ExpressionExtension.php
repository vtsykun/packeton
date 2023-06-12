<?php

declare(strict_types=1);

namespace Packeton\Integrations\Model;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

#[Exclude]
class OAuth2ExpressionExtension extends AbstractExtension
{
    public function __construct(
        protected array $extendFunction = []
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getFunctions(): array
    {
        $functions = [];
        foreach ($this->extendFunction as $name => $func) {
            $functions[] = new TwigFunction($name, $func);
        }

        return array_merge($functions, [
            new TwigFunction('preg_match', 'preg_match'),
            new TwigFunction('json_decode', fn ($data) => json_decode($data, true)),
            new TwigFunction('hash_mac', 'hash_mac'),
            new TwigFunction('array_unique', 'array_unique')
        ]);
    }
}
