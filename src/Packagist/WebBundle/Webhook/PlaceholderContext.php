<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook;

class PlaceholderContext
{
    /**
     * @var array
     */
    private $context = [];

    /**
     * @param string $name
     * @param array $variables
     */
    public function setPlaceholder(string $name, array $variables): void
    {
        $this->context[$name] = $variables;
    }

    /**
     * @return array
     */
    public function getContext()
    {
        return $this->context;
    }
}
