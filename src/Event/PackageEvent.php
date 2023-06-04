<?php

declare(strict_types=1);

namespace Packeton\Event;

use Packeton\Entity\Package;
use Symfony\Contracts\EventDispatcher\Event;

class PackageEvent extends Event
{
    public const PACKAGE_CREATE  = 'packageCreate';

    public function __construct(protected Package $package)
    {
    }

    public function getPackage(): Package
    {
        return $this->package;
    }
}
