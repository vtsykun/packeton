<?php

declare(strict_types=1);

namespace Packeton\Mirror\Exception;

use Packeton\Exception\DebugHttpExceptionInterface;

class MetadataNotFoundException extends \RuntimeException implements DebugHttpExceptionInterface
{
}
