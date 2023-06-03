<?php

declare(strict_types=1);

namespace Packeton\Integrations;

use Packeton\Integrations\Model\AppConfig;

interface IntegrationInterface
{
    public function getConfig(): AppConfig;
}
