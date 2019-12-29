<?php

namespace Packagist\WebBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Webhook
 *
 * @ORM\Table(name="webhook")
 * @ORM\Entity(repositoryClass="Packagist\WebBundle\Repository\WebhookRepository")
 */
class Webhook implements OwnerAwareInterface
{
    public const HOOK_RL_NEW = 'new_release';
    public const HOOK_RL_UPDATE = 'update_release';
    public const HOOK_RL_DELETE = 'delete_release';
    public const HOOK_PUSH_NEW = 'push_new_event';
    public const HOOK_PUSH_UPDATE = 'update_new_event';
    public const HOOK_REPO_FAILED = 'update_repo_failed';
    public const HOOK_REPO_NEW = 'new_repo';
    public const HOOK_REPO_DELETE = 'delete_repo';

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
     * @ORM\Column(name="url", type="string", length=8000)
     */
    private $url;

    /**
     * @var array|null
     *
     * @ORM\Column(name="events", type="simple_array", nullable=true)
     */
    private $events;

    /**
     * @var bool|null
     *
     * @ORM\Column(name="active", type="boolean", nullable=true)
     */
    private $active = true;

    /**
     * @var string|null
     *
     * @ORM\Column(name="package_restriction", type="string", length=8000, nullable=true)
     */
    private $packageRestriction;

    /**
     * @var string|null
     *
     * @ORM\Column(name="version_restriction", type="string", length=1024, nullable=true)
     */
    private $versionRestriction;

    /**
     * @var array|null
     *
     * @ORM\Column(name="options", type="json_array", nullable=true)
     */
    private $options;

    /**
     * @var string|null
     *
     * @ORM\Column(name="payload", type="text", nullable=true)
     */
    private $payload;

    /**
     * @var string|null
     *
     * @ORM\Column(name="method", type="string", length=8, nullable=true)
     */
    private $method;

    /**
     * @var \DateTime|null
     *
     * @ORM\Column(name="created_at", type="datetime", nullable=false)
     */
    private $createdAt;

    /**
     * @var User|null
     *
     * @ORM\ManyToOne(targetEntity="Packagist\WebBundle\Entity\User")
     * @ORM\JoinColumn(name="owner_id", referencedColumnName="id", onDelete="SET NULL")
     */
    private $owner;

    /**
     * @var string|null
     *
     * @ORM\Column(name="visibility", type="string", length=16, nullable=true)
     */
    private $visibility;

    public function __construct()
    {
        $this->createdAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name.
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
     * Get name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set url.
     *
     * @param string $url
     *
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Get url.
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Set events.
     *
     * @param array|null $events
     *
     * @return $this
     */
    public function setEvents($events = null)
    {
        $this->events = $events;

        return $this;
    }

    /**
     * Get events.
     *
     * @return array|null
     */
    public function getEvents()
    {
        return $this->events;
    }

    /**
     * Set active.
     *
     * @param bool|null $active
     *
     * @return $this
     */
    public function setActive($active = null)
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Get active.
     *
     * @return bool|null
     */
    public function getActive()
    {
        return $this->active;
    }

    /**
     * Set packageRestriction.
     *
     * @param string|null $packageRestriction
     *
     * @return $this
     */
    public function setPackageRestriction($packageRestriction = null)
    {
        $this->packageRestriction = $packageRestriction;

        return $this;
    }

    /**
     * Get packageRestriction.
     *
     * @return string|null
     */
    public function getPackageRestriction()
    {
        return $this->packageRestriction;
    }

    /**
     * Set versionRestriction.
     *
     * @param string|null $versionRestriction
     *
     * @return $this
     */
    public function setVersionRestriction($versionRestriction = null)
    {
        $this->versionRestriction = $versionRestriction;

        return $this;
    }

    /**
     * Get versionRestriction.
     *
     * @return string|null
     */
    public function getVersionRestriction()
    {
        return $this->versionRestriction;
    }

    /**
     * Set customHeaders.
     *
     * @param array|null $options
     *
     * @return $this
     */
    public function setOptions($options = null)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Get customHeaders.
     *
     * @return array|null
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Set payload.
     *
     * @param string|null $payload
     *
     * @return $this
     */
    public function setPayload($payload = null)
    {
        $this->payload = $payload;

        return $this;
    }

    /**
     * Get payload.
     *
     * @return string|null
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * Set method.
     *
     * @param string|null $method
     *
     * @return $this
     */
    public function setMethod($method = null)
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Get method.
     *
     * @return string|null
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * @return \DateTime|null
     */
    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    /**
     * @param \DateTime|null $createdAt
     * @return $this
     */
    public function setCreatedAt(?\DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return User|null
     */
    public function getOwner(): ?User
    {
        return $this->owner;
    }

    /**
     * @param User|null $owner
     * @return Webhook
     */
    public function setOwner(?User $owner): Webhook
    {
        $this->owner = $owner;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getVisibility(): ?string
    {
        return $this->visibility;
    }

    /**
     * @param string|null $visibility
     * @return Webhook
     */
    public function setVisibility(?string $visibility): Webhook
    {
        $this->visibility = $visibility;
        return $this;
    }
}
