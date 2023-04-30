<?php

declare(strict_types=1);

namespace Packeton\Entity;

use Doctrine\ORM\Mapping as ORM;
use Packeton\Repository\ApiTokenRepository;

#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table('user_api_token')]
class ApiToken
{
    public const PREFIX = 'pat_';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $apiToken = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $expireAt = null;

    #[ORM\ManyToOne(targetEntity: 'Packeton\Entity\User')]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userIdentifier = null;

    #[ORM\Column(name: 'scores', type: 'json', nullable: true)]
    private ?array $scores = null;

    protected $attributes = [];

    public function __construct()
    {
        $this->createdAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    public function getId()
    {
        return $this->id;
    }

    public function getUserIdentifier(): ?string
    {
        return $this->userIdentifier;
    }

    public function setUserIdentifier(?string $userIdentifier): self
    {
        $this->userIdentifier = $userIdentifier;

        return $this;
    }

    public function getApiToken(): string
    {
        return $this->apiToken;
    }

    public function getToken(): string
    {
        return self::PREFIX . $this->apiToken;
    }

    public function setApiToken(string $apiToken): self
    {
        $this->apiToken = $apiToken;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getExpireAt(): ?\DateTimeInterface
    {
        return $this->expireAt;
    }

    public function setExpireAt(?\DateTimeInterface $expireAt): self
    {
        $this->expireAt = $expireAt;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function getScores(): array
    {
        return $this->scores;
    }

    public function setScores(?array $scores): self
    {
        $this->scores = $scores;

        return $this;
    }

    public function setAttributes(array $attr): void
    {
        $this->attributes = $attr;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttr(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
