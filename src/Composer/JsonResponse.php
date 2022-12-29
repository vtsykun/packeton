<?php

declare(strict_types=1);

namespace Packeton\Composer;

use Symfony\Component\HttpFoundation\JsonResponse as SymfonyJsonResponse;

class JsonResponse extends SymfonyJsonResponse
{
    protected $encodingOptions = 0;
}
