<?php

declare(strict_types=1);

namespace Packeton\Integrations\Model;

use Packeton\Entity\OAuthIntegration as App;

interface FormSettingsInterface
{
    public function getFormSettings(App $app): array;
}
