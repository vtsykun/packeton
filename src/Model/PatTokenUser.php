<?php

declare(strict_types=1);

namespace Packeton\Model;

class PatTokenUser implements PacketonUserInterface
{
    public function __construct(
        protected string $username,
        protected array $roles = [],
        protected array $groups = [],
        protected array $attributes = [],
    ) {
        if (!$username) {
            throw new \InvalidArgumentException('The username cannot be empty.');
        }
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPassword(): ?string
    {
        return null;
    }

    public function getSalt(): ?string
    {
        return null;
    }

    public function eraseCredentials(): void
    {
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    /**
     * @return \DateTimeInterface|null
     */
    public function getExpiredUpdatesAt(): ?\DateTimeInterface
    {
        return $this->attributes['expired_updates'] ?? null;
    }

    public function getScores(): array
    {
        return $this->attributes['scores'] ?? [];
    }

    public function hasScore(string $scope): bool
    {
        return in_array($scope, $this->getScores(), true);
    }

    /**
     * {@inheritdoc}
     */
    public function getAclGroups(): ?array
    {
        return $this->groups;
    }

    /**
     * {@inheritdoc}
     */
    public function getSubRepos(): ?array
    {
        return $this->attributes['sub_repos'] ?? null;
    }
}
