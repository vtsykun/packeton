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

    #[ORM\Column(name: 'proxies', type: 'simple_array', nullable: true)]
    private ?array $proxies = null;

    /**
     * @var GroupAclPermission[]|Collection
     */
    #[ORM\OneToMany(mappedBy: "group", targetEntity: GroupAclPermission::class, cascade: ["all"], orphanRemoval: true)]
    private $aclPermissions;

    public function __construct()
    {
        $this->aclPermissions = new ArrayCollection();
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
}
