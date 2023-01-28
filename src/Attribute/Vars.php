<?php

declare(strict_types=1);

namespace Packeton\Attribute;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Vars
{
    public function __construct(
        public readonly null|string|array $map = null,
    ) {
    }
}
