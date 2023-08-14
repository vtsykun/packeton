<?php

namespace Packeton\Event;

use Packeton\Entity\Package;
use Symfony\Contracts\EventDispatcher\Event;

class SecurityAdvisoryEvent extends Event
{
    public const PACKAGE_ADVISORY  = 'packageAdvisory';

    public function __construct(
        private readonly Package $package,
        private readonly array $advisories,
        private readonly array $allAdvisories
    ) {
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
    public function getAdvisories(): array
    {
        return $this->advisories;
    }

    /**
     * @return array
     */
    public function getAllAdvisories(): array
    {
        return $this->allAdvisories;
    }
}
