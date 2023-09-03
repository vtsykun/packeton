<?php

declare(strict_types=1);

namespace Packeton\Form\Model;

use Packeton\Entity\OAuthIntegration;
use Packeton\Entity\SshCredentials;

class ImportRequest
{
    public ?string $type = null;
    public ?string $clone = null;
    public ?string $filter = null;
    public ?int $limit = null;
    public ?SshCredentials $credentials = null;
    public ?OAuthIntegration $integration = null;
    public ?array $integrationRepos = [];
    public ?bool $integrationInclude = null;
    public ?string $composerUrl = null;
    public ?string $username = null;
    public ?string $password = null;
    public ?string $packageFilter = null;
    public ?string $repoList = null;
}
