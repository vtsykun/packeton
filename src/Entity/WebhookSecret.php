<?php

declare(strict_types=1);

namespace Packeton\Entity;

use Doctrine\ORM\Mapping as ORM;
use Packeton\Repository\WebhookSecretRepository;

#[ORM\Entity(repositoryClass: WebhookSecretRepository::class)]
#[ORM\Table(name: 'webhook_secret')]
class WebhookSecret
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'encrypted_text')]
    private ?string $secret = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastUsedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function setSecret(string $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeInterface
    {
        return $this->lastUsedAt;
    }

    public function updateLastUsedAt(): self
    {
        $this->lastUsedAt = new \DateTime('now', new \DateTimeZone('UTC'));

        return $this;
    }

    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
