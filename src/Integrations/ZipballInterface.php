<?php

declare(strict_types=1);

namespace Packeton\Integrations;

use Packeton\Entity\OAuthIntegration as App;

interface ZipballInterface
{
    public function zipballDownload(App $accessToken, string|int $repoId, string $reference, string $savePath = null): string;
}
