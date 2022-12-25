<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Entity;

interface OwnerAwareInterface
{
    public const USER_VISIBLE = 'user';
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
