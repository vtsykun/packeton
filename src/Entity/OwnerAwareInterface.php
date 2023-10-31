<?php

declare(strict_types=1);

namespace Packeton\Entity;

interface OwnerAwareInterface
{
    /**
     * Visible for owner and if owner is not null
     */
    public const STRICT_VISIBLE = 'strict';

    /**
     * Visible for owner or if owner is null
     */
    public const USER_VISIBLE = 'user';

    /**
     * Visible for all users
     */
    public const GLOBAL_VISIBLE = 'global';

    /**
     * @return User|null
     */
    public function getOwner(): ?User;

    /**
     * @return string|null
     */
    public function getVisibility(): ?string;
}
