<?php

namespace Packeton\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Packeton\Repository\GroupRepository;

#[ORM\Entity(repositoryClass: GroupRepository::class)]
#[ORM\Table('user_group')]
class Group
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id = null;

    #[ORM\Column(name: 'name', length: 64, unique: true)]
    private ?string $name = null;

    #[ORM\Column(name: 'expired_updates_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $expiredUpdatesAt = null;

    #[ORM\Column(name: 'proxies', type: 'simple_array', nullable: true)]
    private ?array $proxies = null;

    /**
     * @var GroupAclPermission[]|Collection
     */
    #[ORM\OneToMany(mappedBy: "group", targetEntity: GroupAclPermission::class, cascade: ["all"], orphanRemoval: true)]
    private $aclPermissions;

    /**
     * @var User[]|Collection
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'ownedGroups')]
    #[ORM\JoinTable(name: 'group_owner')]
    private Collection $owners;

    public function __construct()
    {
        $this->aclPermissions = new ArrayCollection();
        $this->owners = new ArrayCollection();
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
     * @return array
     */
    public function getProxies()
    {
        return $this->proxies;
    }

    /**
     * @param array $proxies
     * @return Group
     */
    public function setProxies(?array $proxies)
    {
        $this->proxies = $proxies;
        return $this;
    }

    /**
     * @return GroupAclPermission[]|Collection
     */
    public function getAclPermissions()
    {
        return $this->aclPermissions;
    }

    /**
     * @param GroupAclPermission[] $aclPermissions
     * @return Group
     */
    public function setAclPermissions($aclPermissions)
    {
        if ($aclPermissions) {
            if ($this->aclPermissions instanceof Collection) {
                $this->aclPermissions->clear();
            }
            foreach ($aclPermissions as $permission) {
                $permission->setGroup($this);
                $this->aclPermissions->add($permission);
            }
        }

        return $this;
    }

    /**
     * @param GroupAclPermission $permission
     *
     * @return boolean
     */
    public function hasAclPermissions(GroupAclPermission $permission)
    {
        return $this->aclPermissions->contains($permission);
    }

    public function addAclPermissions(GroupAclPermission $permission)
    {
        if (!$this->aclPermissions->contains($permission)) {
            $this->aclPermissions->add($permission);
            $permission->setGroup($this);
        }

        return $this;
    }

    public function removeAclPermissions(GroupAclPermission $permission)
    {
        if ($this->aclPermissions->contains($permission)) {
            $this->aclPermissions->removeElement($permission);
            $permission->setGroup();
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getExpiredUpdatesAt(): ?\DateTimeInterface
    {
        return $this->expiredUpdatesAt;
    }

    /**
     * @param \DateTimeInterface $expiredUpdatesAt
     * @return $this
     */
    public function setExpiredUpdatesAt(?\DateTimeInterface $expiredUpdatesAt)
    {
        $this->expiredUpdatesAt = $expiredUpdatesAt;
        return $this;
    }

    /**
     * @return Collection|User[]
     */
    public function getOwners(): Collection
    {
        return $this->owners;
    }

    /**
     * @param User $user
     * @return $this
     */
    public function addOwner(User $user): self
    {
        if (!$this->owners->contains($user)) {
            $this->owners->add($user);
        }

        return $this;
    }

    /**
     * @param User $user
     * @return $this
     */
    public function removeOwner(User $user): self
    {
        $this->owners->removeElement($user);

        return $this;
    }

    /**
     * @param User $user
     * @return bool
     */
    public function hasOwner(User $user): bool
    {
        return $this->owners->contains($user);
    }
}
