<?php

declare(strict_types=1);

namespace Packeton\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsWorker
{
    public function __construct(public readonly string $topic)
    {
    }
}
