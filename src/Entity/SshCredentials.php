<?php

namespace Packeton\Entity;

use Doctrine\ORM\Mapping as ORM;
use Packeton\Model\CredentialsInterface;

/**
 * SshCredentials
 *
 * @ORM\Table(name="ssh_credentials")
 * @ORM\Entity()
 */
class SshCredentials implements OwnerAwareInterface, CredentialsInterface
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="name", type="string", length=255)
     */
    private $name;

    /**
     * @var string
     *
     * @ORM\Column(name="ssh_key", type="encrypted_text", nullable=true)
     */
    private $key;

    /**
     * @var array|null
     *
     * @ORM\Column(name="composer_config", type="encrypted_array", nullable=true)
     */
    private $composerConfig;

    /**
     * @var User|null
     *
     * @ORM\ManyToOne(targetEntity="Packeton\Entity\User")
     * @ORM\JoinColumn(name="owner_id", referencedColumnName="id", onDelete="SET NULL")
     */
    private $owner;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="createdat", type="datetime")
     */
    private $createdAt;

    /**
     * @var string
     *
     * @ORM\Column(name="fingerprint", type="string", length=255, nullable=true)
     */
    private $fingerprint;

    public function __construct()
    {
        $this->createdAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    /**
     * Get id
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name
     *
     * @param string $name
     *
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set key
     *
     * @param string $key
     *
     * @return $this
     */
    public function setKey($key)
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Get key
     *
     * @return string
     */
    public function getKey(): ?string
    {
        return $this->key;
    }

    /**
     * Set createdAt
     *
     * @param \DateTime $createdAt
     *
     * @return $this
     */
    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * Get createdAt
     *
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * Set fingerprint
     *
     * @param string $fingerprint
     *
     * @return $this
     */
    public function setFingerprint($fingerprint)
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }

    /**
     * Get fingerprint
     *
     * @return string
     */
    public function getFingerprint()
    {
        return $this->fingerprint;
    }

    /**
     * @param string $name
     *
     * @return mixed|null
     */
    public function getComposerConfigOption(string $name): mixed
    {
        return $this->composerConfig[$name] ?? null;
    }

    /**
     * @return array|null
     */
    public function getComposerConfig(): ?array
    {
        return $this->composerConfig;
    }

    /**
     * @param array|null $composerConfig
     * @return $this
     */
    public function setComposerConfig(?array $composerConfig)
    {
        $this->composerConfig = $composerConfig;
        return $this;
    }

    /**
     * @param User|null $owner
     * @return $this
     */
    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getOwner(): ?User
    {
        return $this->owner;
    }

    /**
     * {@inheritdoc}
     */
    public function getVisibility(): ?string
    {
        return OwnerAwareInterface::STRICT_VISIBLE;
    }

    public function getPrivkeyFile(): ?string
    {
        return null;
    }
}
