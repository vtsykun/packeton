<?php

namespace Packeton\Exception;

class JobException extends \Exception
{
    public function __construct(\Throwable $previous, private readonly ?string $details)
    {
        parent::__construct($previous->getMessage(), $previous->getCode(), $previous);
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }
}
