<?php

declare(strict_types=1);

namespace Packeton\Integrations\Factory;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class OAuth2Factory implements OAuth2FactoryInterface
{
    use OAuth2FactoryTrait;

    public function __construct(private readonly string $key, private readonly string $class)
    {
    }
}
