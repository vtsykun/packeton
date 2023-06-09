<?php

declare(strict_types=1);

namespace Packeton\Integrations\Github;

use Packeton\Integrations\Factory\OAuth2FactoryInterface;
use Packeton\Integrations\Factory\OAuth2FactoryTrait;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class GithubOAuth2Factory implements OAuth2FactoryInterface
{
    protected $class = GitHubIntegration::class;
    protected $key = 'github';

    use OAuth2FactoryTrait;
}
