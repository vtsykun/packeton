<?php

declare(strict_types=1);

namespace Packeton\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class AsIntegration
{
    public function __construct(public readonly string $nameOrClass)
    {
    }
}
