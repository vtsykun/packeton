<?php

declare(strict_types=1);

namespace Packeton\Model;

use Symfony\Component\Security\Core\User\UserInterface;

interface PacketonUserInterface extends UserInterface
{
    /**
     * @return array|int[]|null
     */
    public function getAclGroups(): ?array;

    /**
     * @return \DateTimeInterface|null
     */
    public function getExpiredUpdatesAt(): ?\DateTimeInterface;
}
