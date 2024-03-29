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

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class PackageLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'packagename', length: 191)]
    private ?string $packageName = null;

    #[ORM\Column(name: 'packageversion', type: 'text')]
    private $packageVersion;

    /**
     * Base property holding the version - this must remain protected since it
     * is redefined with an annotation in the child class
     */
    protected ?Version $version = null;

    public function toArray()
    {
        return [$this->getPackageName() => $this->getPackageVersion()];
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getId()
    {
        return $this->id;
    }

    /**
     * Set packageName
     *
     * @param string $packageName
     */
    public function setPackageName($packageName)
    {
        $this->packageName = $packageName;
    }

    /**
     * Get packageName
     *
     * @return string
     */
    public function getPackageName()
    {
        return $this->packageName;
    }

    /**
     * Set packageVersion
     *
     * @param string $packageVersion
     */
    public function setPackageVersion($packageVersion)
    {
        $this->packageVersion = $packageVersion;
    }

    /**
     * Get packageVersion
     *
     * @return string
     */
    public function getPackageVersion()
    {
        return $this->packageVersion;
    }

    /**
     * Set version
     *
     * @param Version $version
     */
    public function setVersion(Version $version)
    {
        $this->version = $version;
    }

    /**
     * Get version
     *
     * @return Version
     */
    public function getVersion()
    {
        return $this->version;
    }

    public function __toString()
    {
        return $this->packageName.' '.$this->packageVersion;
    }
}
