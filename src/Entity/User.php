<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packeton\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Packeton\Model\BaseUser;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * @ORM\Entity(repositoryClass="Packeton\Repository\UserRepository")
 * @ORM\Table(
 *     name="fos_user",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(columns={"email_canonical"}),
 *         @ORM\UniqueConstraint(columns={"username_canonical"}),
 *         @ORM\UniqueConstraint(columns={"confirmation_token"})
 *      }
 * )
 * @UniqueEntity(fields={"username"})
 * @UniqueEntity(fields={"email"})
 */
class User extends BaseUser
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var string
     * @ORM\Column(name="username", type="string", length=191)
     */
    protected $username;

    /**
     * @var string
     * @ORM\Column(name="username_canonical", type="string", length=191)
     */
    protected $usernameCanonical;

    /**
     * @ORM\Column(name="email", type="string", length=191)
     */
    protected $email;

    /**
     * @ORM\Column(name="email_canonical", type="string", length=191)
     */
    protected $emailCanonical;

    /**
     * @var Group[]|Collection
     *
     * @ORM\ManyToMany(targetEntity="Packeton\Entity\Group")
     * @ORM\JoinTable(name="fos_user_access_group",
     *      joinColumns={@ORM\JoinColumn(name="user_id", referencedColumnName="id", onDelete="CASCADE")},
     *      inverseJoinColumns={@ORM\JoinColumn(name="group_id", referencedColumnName="id", onDelete="CASCADE")}
     * )
     */
    private $groups;

    /**
     * @var Package[]
     *
     * @ORM\ManyToMany(targetEntity="Package", mappedBy="maintainers")
     */
    private $packages;

    /**
     * @var Author[]
     *
     * @ORM\OneToMany(targetEntity="Packeton\Entity\Author", mappedBy="owner")
     */
    private $authors;

    /**
     * @ORM\Column(type="datetime", name="createdat")
     */
    private $createdAt;

    /**
     * @ORM\Column(type="string", length=20, nullable=true, name="apitoken")
     * @var string
     */
    private $apiToken;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, name="githubid")
     * @var string
     */
    private $githubId;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, name="githubtoken")
     * @var string
     */
    private $githubToken;

    /**
     * @ORM\Column(type="boolean", options={"default"=true}, name="failurenotifications")
     * @var string
     */
    private $failureNotifications = true;

    /**
     * @ORM\Column(name="expires_at", type="date", nullable=true)
     * @var \DateTime
     */
    private $expiresAt;

    /**
     * Disable to updates a new release after this date expired
     *
     * @ORM\Column(name="expired_updates_at", type="date", nullable=true)
     * @var \DateTime
     */
    private $expiredUpdatesAt;

    public function __construct()
    {
        $this->packages = new ArrayCollection();
        $this->authors = new ArrayCollection();
        $this->groups = new ArrayCollection();
        $this->createdAt = new \DateTime();
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function toArray()
    {
        return [
            'name' => $this->username,
            'avatar_url' => $this->getGravatarUrl(),
        ];
    }

    /**
     * Add packages
     *
     * @param Package $packages
     */
    public function addPackages(Package $packages)
    {
        $this->packages[] = $packages;
    }

    /**
     * Get packages
     *
     * @return Package[]
     */
    public function getPackages()
    {
        return $this->packages;
    }

    /**
     * Add authors
     *
     * @param Author $authors
     */
    public function addAuthors(Author $authors)
    {
        $this->authors[] = $authors;
    }

    /**
     * Get authors
     *
     * @return Author[]
     */
    public function getAuthors()
    {
        return $this->authors;
    }

    /**
     * Set createdAt
     *
     * @param \DateTime $createdAt
     */
    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;
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
     * Set apiToken
     *
     * @param string $apiToken
     */
    public function setApiToken($apiToken)
    {
        $this->apiToken = $apiToken;
    }

    /**
     * Get apiToken
     *
     * @return string
     */
    public function getApiToken()
    {
        return $this->apiToken;
    }

    /**
     * Get githubId.
     *
     * @return string
     */
    public function getGithubId()
    {
        return $this->githubId;
    }

    /**
     * Set githubId.
     *
     * @param string $githubId
     */
    public function setGithubId($githubId)
    {
        $this->githubId = $githubId;
    }

    /**
     * Get githubId.
     *
     * @return string
     */
    public function getGithubToken()
    {
        return $this->githubToken;
    }

    /**
     * Set githubToken.
     *
     * @param string $githubToken
     */
    public function setGithubToken($githubToken)
    {
        $this->githubToken = $githubToken;
    }

    /**
     * Set failureNotifications
     *
     * @param Boolean $failureNotifications
     */
    public function setFailureNotifications($failureNotifications)
    {
        $this->failureNotifications = $failureNotifications;
    }

    /**
     * Get failureNotifications
     *
     * @return Boolean
     */
    public function getFailureNotifications()
    {
        return $this->failureNotifications;
    }

    /**
     * Get failureNotifications
     *
     * @return Boolean
     */
    public function isNotifiableForFailures()
    {
        return $this->failureNotifications;
    }

    /**
     * Get Gravatar Url
     *
     * @return string
     */
    public function getGravatarUrl()
    {
        return 'https://www.gravatar.com/avatar/'.md5(strtolower($this->getEmail())).'?d=identicon';
    }

    public function setGroups($groups)
    {
        $this->groups->clear();
        if (\is_iterable($groups)) {
            foreach ($groups as $group) {
                $this->groups->add($group);
            }
        }
    }

    /**
     * @return ArrayCollection|Group[]
     */
    public function getGroups()
    {
        return $this->groups;
    }

    public function addGroup($group)
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
        }
    }

    public function removeGroup($group)
    {
        $this->groups->removeElement($group);
    }

    /**
     * Returns the user roles
     *
     * @return array The roles
     */
    public function getRoles()
    {
        $roles = $this->roles;
        // we need to make sure to have at least one role
        $roles[] = static::ROLE_DEFAULT;

        return array_unique($roles);
    }

    /**
     * @return \DateTime
     */
    public function getExpiresAt()
    {
        return $this->expiresAt;
    }

    /**
     * @param \DateTime $expiresAt
     * @return $this
     */
    public function setExpiresAt($expiresAt)
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function generateApiToken()
    {
        $this->apiToken = str_replace(['+', '/', '='], '', base64_encode(random_bytes(32)));
        $this->apiToken = substr($this->apiToken, 0, 20);
        return $this->apiToken;
    }

    /**
     * {@inheritdoc}
     */
    public function isAccountNonExpired()
    {
        if (null === $this->expiresAt) {
            return true;
        }

        return new \DateTime('now') < $this->expiresAt;
    }

    /**
     * @return bool
     */
    public function isAdmin()
    {
        return $this->hasRole('ROLE_ADMIN') || $this->isSuperAdmin();
    }

    /**
     * @return bool
     */
    public function isMaintainer()
    {
        return $this->hasRole('ROLE_MAINTAINER') || $this->isAdmin() || $this->isSuperAdmin();
    }

    /**
     * @return \DateTime|null
     */
    public function getExpiredUpdatesAt()
    {
        return $this->expiredUpdatesAt;
    }

    /**
     * @param \DateTime $expiredUpdatesAt
     * @return $this
     */
    public function setExpiredUpdatesAt($expiredUpdatesAt)
    {
        $this->expiredUpdatesAt = $expiredUpdatesAt;
        return $this;
    }
}
