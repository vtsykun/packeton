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
use Doctrine\ORM\Mapping as ORM;
use Packeton\Model\BaseUser;
use Packeton\Model\PacketonUserInterface;
use Packeton\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'fos_user')]
#[ORM\UniqueConstraint(columns: ['email_canonical'])]
#[ORM\UniqueConstraint(columns: ['username_canonical'])]
#[UniqueEntity(fields: ['username'], message: 'There is already an account with this username', errorPath: 'username')]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email', errorPath: 'email')]
class User extends BaseUser implements PacketonUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\Column(name: 'username', type: 'string', length: 191)]
    protected $username;

    #[ORM\Column(name: 'username_canonical', type: 'string', length: 191)]
    protected $usernameCanonical;

    #[ORM\Column(name: 'email', type: 'string', length: 191)]
    protected $email;

    #[ORM\Column(name: 'email_canonical', type: 'string', length: 191)]
    protected $emailCanonical;

    #[ORM\ManyToMany(targetEntity: 'Packeton\Entity\Group')]
    #[ORM\JoinTable(name: 'fos_user_access_group')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'group_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private $groups;

    #[ORM\ManyToMany(targetEntity: 'Packeton\Entity\Package', mappedBy: 'maintainers')]
    private $packages;

    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: 'Packeton\Entity\Author')]
    private $authors;

    #[ORM\Column(name: 'createdat', type: 'datetime')]
    private $createdAt;

    #[ORM\Column(name: 'apitoken', type: 'string', length: 20, nullable: true)]
    private ?string $apiToken = null;

    #[ORM\Column(name: 'githubid', type: 'string', length: 255, nullable: true)]
    private ?string $githubId = null;

    #[ORM\Column(name: 'githubtoken', type: 'string', length: 255, nullable: true)]
    private ?string $githubToken = null;

    #[ORM\Column(name: 'failurenotifications', type: 'boolean', options: ['default' => true])]
    private $failureNotifications = true;

    #[ORM\Column(name: 'expires_at', type: 'date', nullable: true)]
    private $expiresAt;

    #[ORM\Column(name: 'expired_updates_at', type: 'date', nullable: true)]
    private $expiredUpdatesAt;

    #[ORM\Column(name: 'sub_repos', type: 'json', nullable: true)]
    private ?array $subRepos = null;

    public function __construct()
    {
        $this->packages = new ArrayCollection();
        $this->authors = new ArrayCollection();
        $this->groups = new ArrayCollection();
        $this->createdAt = new \DateTime();
        parent::__construct();
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
     * @return Package[]|ArrayCollection
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
     * @return Author[]|ArrayCollection
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
     * @return $this
     */
    public function setGithubId($githubId)
    {
        $this->githubId = $githubId;

        return $this;
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

    /**
     * {@inheritdoc}
     */
    public function getAclGroups(): array
    {
        return $this->groups->map(fn(Group $g) => $g->getId())->toArray();
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
    public function getRoles(): array
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

    public function isExternal(): bool
    {
        return $this->hasRole('ROLE_OAUTH') || $this->githubId !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function getExpiredUpdatesAt(): ?\DateTimeInterface
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

    /**
     * {@inheritdoc}
     */
    public function getSubRepos(): ?array
    {
        return $this->subRepos;
    }

    public function setSubRepos(?array $subRepos): static
    {
        $this->subRepos = $subRepos;

        return $this;
    }

    public function getSubReposView(): array
    {
        return $this->subRepos ?: [0];
    }


    public function setSubReposView(?array $subRepos): self
    {
        if ($subRepos && count($subRepos) === 1 && 0 === ((int)reset($subRepos))) {
            $subRepos = null;
        }

        $this->subRepos = $subRepos ? array_values(array_map(fn($id) => (int) $id, $subRepos)) : null;

        return $this;
    }
}
