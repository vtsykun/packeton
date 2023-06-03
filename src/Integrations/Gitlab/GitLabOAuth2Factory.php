<?php

declare(strict_types=1);

namespace Packeton\Integrations\Gitlab;

use Packeton\Integrations\Factory\OAuth2FactoryInterface;
use Packeton\Integrations\Factory\OAuth2FactoryTrait;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class GitLabOAuth2Factory implements OAuth2FactoryInterface
{
    protected $class = GitLabIntegration::class;
    protected $key = 'gitlab';

    use OAuth2FactoryTrait;
}
