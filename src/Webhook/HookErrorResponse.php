<?php

declare(strict_types=1);

namespace Packeton\Webhook;

class HookErrorResponse extends HookResponse
{
    private $errorMessage;

    public function __construct($errorMessage = null)
    {
        $this->errorMessage = $errorMessage;
        parent::__construct(new HookRequest('null', 'null'));
    }

    /**
     * @return string
     */
    public function getErrorMessage()
    {
        return $this->errorMessage;
    }
}
