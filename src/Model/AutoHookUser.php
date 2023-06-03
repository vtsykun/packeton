<?php

declare(strict_types=1);

namespace Packeton\Model;

use Symfony\Component\Security\Core\User\UserInterface;

final class AutoHookUser implements UserInterface
{
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
        return 'token_hooks';
    }
}
