<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Event;

use Packagist\WebBundle\Entity\Package;
use Symfony\Component\EventDispatcher\Event;

class UpdaterEvent extends Event
{
    public const VERSIONS_UPDATE  = 'packageRefresh';
    public const PACKAGE_PERSIST  = 'packagePersist';

    private $package;
    private $created;
    private $updated;
    private $deleted;
    private $flags;

    public function __construct(Package $package, $flags = 0, array $created = [], array $updated = [], array $deleted = [])
    {
        $this->package = $package;
        $this->created = $created;
        $this->updated = $updated;
        $this->deleted = $deleted;
        $this->flags = $flags;
    }

    /**
     * @return Package
     */
    public function getPackage(): Package
    {
        return $this->package;
    }

    /**
     * @return array
     */
    public function getCreated(): array
    {
        return $this->created;
    }

    /**
     * @return array
     */
    public function getUpdated(): array
    {
        return $this->updated;
    }

    /**
     * @return array
     */
    public function getDeleted(): array
    {
        return $this->deleted;
    }

    /**
     * @return mixed
     */
    public function getFlags()
    {
        return $this->flags;
    }
}
