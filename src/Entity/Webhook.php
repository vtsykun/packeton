<?php

namespace Packeton\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'Packeton\Repository\WebhookRepository')]
#[ORM\Table('webhook')]
class Webhook implements OwnerAwareInterface
{
    public const HOOK_RL_NEW = 'new_release';
    public const HOOK_RL_UPDATE = 'update_release';
    public const HOOK_RL_DELETE = 'delete_release';
    public const HOOK_PUSH_NEW = 'push_new_event';
    public const HOOK_PUSH_UPDATE = 'update_new_event';
    public const HOOK_HTTP_REQUEST = 'http_request';
    public const HOOK_CRON = 'cron_trigger';
    public const HOOK_USER_LOGIN = 'user_login';
    public const HOOK_REPO_FAILED = 'update_repo_failed';
    public const HOOK_REPO_NEW = 'new_repo';
    public const HOOK_REPO_DELETE = 'delete_repo';

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 255)]
    private ?string $name = null;

    #[ORM\Column(name: 'url', type: 'text')]
    private ?string $url = null;

    #[ORM\Column(name: 'events', type: 'simple_array', nullable: true)]
    private ?array $events = null;

    #[ORM\Column(name: 'active', type: 'boolean', nullable: true)]
    private ?bool $active = true;

    #[ORM\Column(name: 'package_restriction', type: 'text', nullable: true)]
    private ?string $packageRestriction = null;

    #[ORM\Column(name: 'version_restriction', type: 'string', length: 1024, nullable: true)]
    private ?string $versionRestriction = null;

    #[ORM\Column(name: 'options', type: 'json', nullable: true)]
    private ?array $options = null;

    #[ORM\Column(name: 'payload', type: 'text', nullable: true)]
    private ?string $payload = null;

    #[ORM\Column(name: 'method', type: 'string', length: 8, nullable: true)]
    private ?string $method = null;

    #[ORM\Column(name: 'created_at', type: 'datetime', nullable: false)]
    private \DateTimeInterface $createdAt;

    #[ORM\ManyToOne(targetEntity: 'Packeton\Entity\User')]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\Column(name: 'visibility', type: 'string', length: 16, nullable: true)]
    private ?string $visibility = null;

    #[ORM\Column(name: 'cron', type: 'string', length: 255, nullable: true)]
    private ?string $cron = null;

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

    public function getSchemeAndHttpHost()
    {
        $parsed = parse_url($this->url);
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $user = $parsed['user'] ?? '';
        $pass = isset($parsed['pass']) ? ':' . $parsed['pass']  : '';
        $pass = ($user || $pass) ? "$pass@" : '';

        return "$scheme$user$pass$host$port";
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
     * @param string ...$events
     * @return bool
     */
    public function matchAllEvents(...$events): bool
    {
        foreach ($events as $event) {
            if (false === in_array($event, $this->getEvents() ?: [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string ...$events
     * @return bool
     */
    public function matchAnyEvents(...$events): bool
    {
        foreach ($events as $event) {
            if (true === in_array($event, $this->getEvents() ?: [])) {
                return true;
            }
        }

        return false;
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

    /**
     * @return string|null
     */
    public function getCron()
    {
        return $this->cron;
    }

    /**
     * @param string|null $cron
     * @return Webhook
     */
    public function setCron($cron)
    {
        $this->cron = $cron;
        return $this;
    }
}
