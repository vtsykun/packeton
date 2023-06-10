<?php

declare(strict_types=1);

namespace Packeton\Integrations\Exception;

use Packeton\Exception\SkipLoggerExceptionInterface;

class GitHubAppException extends \RuntimeException implements SkipLoggerExceptionInterface
{
}
