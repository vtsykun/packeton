<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook;

use Twig\Environment;
use Twig\Loader\ArrayLoader;

class PayloadRenderer extends Environment
{
    public function __construct($options = [])
    {
        $loader = new ArrayLoader();
        parent::__construct($loader, $options);
    }
}
