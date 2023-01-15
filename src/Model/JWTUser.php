<?php

declare(strict_types=1);

namespace Packeton\Model;

use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * This user class used in API.
 */
final class JWTUser implements UserInterface, EquatableInterface
{
    public function __construct(
        private readonly string $username,
        private readonly array $roles = []
    ) {
        if (!$username) {
            throw new \InvalidArgumentException('The username cannot be empty.');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * {@inheritdoc}
     */
    public function getPassword(): ?string
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getSalt(): ?string
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
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
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $this->isEqualUserAttributes($user);
    }

    public function isEqualUserAttributes(UserInterface $user): bool
    {
        if ($this->getUserIdentifier() !== $user->getUserIdentifier()) {
            return false;
        }

        if (array_diff($this->roles, $user->roles) || array_diff($user->roles, $this->roles)) {
            return false;
        }

        return true;
    }
}
