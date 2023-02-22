<?php

declare(strict_types=1);

namespace Packeton\Model;

class ComposerCredentials implements CredentialsInterface
{
    public function __construct(
        private readonly ?string $sshKey = null,
        private readonly ?string $sshKeyFile = null,
        private readonly ?array $authConfig = null,
    ) {
    }

    public function getKey(): ?string
    {
        return $this->sshKey;
    }

    public function getPrivkeyFile(): ?string
    {
        return $this->sshKeyFile;
    }

    public function getComposerConfig(): ?array
    {
        return $this->authConfig;
    }

    public function getComposerConfigOption(string $name): mixed
    {
        return $this->authConfig[$name] ?? null;
    }
}
