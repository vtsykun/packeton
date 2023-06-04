<?php

declare(strict_types=1);

namespace Packeton\Model;

use Symfony\Component\Security\Core\User\UserInterface;

class AutoHookUser implements UserInterface
{
    public function __construct(protected string|int $hookIdentifier)
    {
    }

    public function getHookIdentifier(): string|int
    {
        return $this->hookIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    public function getRoles(): array
    {
        return ['ROLE_USER', 'ROLE_MAINTAINER'];
    }

    /**
     * {@inheritdoc}
     */
    public function eraseCredentials()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getUserIdentifier(): string
    {
        return 'token_hooks'.$this->hookIdentifier;
    }
}
