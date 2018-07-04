<?php

namespace Packagist\WebBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;

/**
 * Group
 *
 * @ORM\Table(name="user_group")
 * @ORM\Entity(repositoryClass="Packagist\WebBundle\Entity\GroupRepository")
 */
class Group
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="name", type="string", length=64, unique=true)
     */
    private $name;

    /**
     * @var GroupAclPermission[]|Collection
     *
     * @ORM\OneToMany(targetEntity="Packagist\WebBundle\Entity\GroupAclPermission", mappedBy="group", cascade={"all"}, orphanRemoval=true)
     */
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
            $permission->setGroup(null);
        }

        return $this;
    }
}
