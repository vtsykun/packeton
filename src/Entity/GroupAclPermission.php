<?php

namespace Packagist\WebBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * GroupAclPermission
 *
 * @ORM\Table(name="group_acl_permission")
 * @ORM\Entity()
 */
class GroupAclPermission
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
     * @ORM\Column(name="version", type="string", length=64, nullable=true)
     */
    private $version;

    /**
     * @var Group
     *
     * @ORM\ManyToOne(targetEntity="Packagist\WebBundle\Entity\Group", inversedBy="aclPermissions")
     * @ORM\JoinColumn(name="group_id", referencedColumnName="id", onDelete="CASCADE")
     */
    private $group;

    /**
     * @var Package
     *
     * @ORM\ManyToOne(targetEntity="Packagist\WebBundle\Entity\Package")
     * @ORM\JoinColumn(name="package_id", referencedColumnName="id", onDelete="CASCADE")
     */
    private $package;

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
    public function setVersion($version)
    {
        $this->version = $version;

        return $this;
    }

    /**
     * Get version
     *
     * @return string
     */
    public function getVersion()
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
    public function setGroup(Group $group)
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
