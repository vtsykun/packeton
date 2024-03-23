<?php

namespace Packeton\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table('group_acl_permission')]
class GroupAclPermission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id = null;

    #[ORM\Column(name: 'version', length: 64, nullable: true)]
    private ?string $version = null;

    #[ORM\ManyToOne(targetEntity: Group::class, inversedBy: 'aclPermissions')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Group $group = null;

    #[ORM\ManyToOne(targetEntity: Package::class)]
    #[ORM\JoinColumn(name: 'package_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Package $package = null;

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
     * Set version
     *
     * @param string $version
     *
     * @return GroupAclPermission
     */
    public function setVersion(?string $version)
    {
        $this->version = $version;

        return $this;
    }

    /**
     * Get version
     *
     * @return string
     */
    public function getVersion(): ?string
    {
        return $this->version;
    }

    /**
     * @return Group
     */
    public function getGroup()
    {
        return $this->group;
    }

    /**
     * @param Group $group
     * @return GroupAclPermission
     */
    public function setGroup(?Group $group = null)
    {
        $this->group = $group;
        return $this;
    }

    /**
     * @return Package
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * @param Package $package
     * @return GroupAclPermission
     */
    public function setPackage(Package $package)
    {
        $this->package = $package;
        return $this;
    }
}
