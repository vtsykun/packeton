<?php

declare(strict_types=1);

namespace Packeton\Exception;

class SignalException extends \RuntimeException
{
    public function __construct(string $message = "Process was interrupted by signal", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
