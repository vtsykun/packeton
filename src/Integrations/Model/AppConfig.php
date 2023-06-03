<?php

declare(strict_types=1);

namespace Packeton\Integrations\Model;

class AppConfig
{
    protected $defaultRoles = ['ROLE_MAINTAINER', 'ROLE_OAUTH'];

    public function __construct(protected array $config)
    {
        $defaultRoles = $this->config['oauth2_registration']['default_roles'] ?? [];
        $this->defaultRoles = array_values(array_unique(array_merge(['ROLE_USER'], $defaultRoles ?: $this->defaultRoles)));
    }

    public function isRegistration(): bool
    {
        return $this->config['oauth2_registration']['enabled'] ?? false;
    }

    public function clonePref(): string
    {
        return $this->config['clone_preference'] ?? 'api';
    }

    public function isLogin(): bool
    {
        return $this->config['oauth2_login'] ?? false;
    }

    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }

    public function getClientId(): ?string
    {
        return $this->config['client_id'] ?? null;
    }

    public function getCaps(): array
    {
        $caps = ['APP' => true, 'ALLOW_LOGIN' => $this->isLogin(), 'ALLOW_REGISTRATION' => $this->isRegistration()];

        return array_keys(array_filter($caps));
    }

    public function getClientSecret(): ?string
    {
        return $this->config['client_secret'] ?? null;
    }

    public function roles(): array
    {
        return $this->defaultRoles;
    }

    public function getName(): string
    {
        return $this->config['name'];
    }

    public function getRedirectUrls(): array
    {
        return $this->config['redirect_urls'] ?? [];
    }

    public function getHookUrl(): ?string
    {
        return $this->config['hook_url'] ?? null;
    }
}
