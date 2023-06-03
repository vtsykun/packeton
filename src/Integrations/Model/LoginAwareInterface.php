<?php

declare(strict_types=1);

namespace Packeton\Integrations\Model;

interface LoginAwareInterface
{
    public static function isLoginEnabled(): bool;
}
