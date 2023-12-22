<?php

declare(strict_types=1);

namespace Packeton\Event;

use Packeton\Entity\Package;
use Symfony\Contracts\EventDispatcher\Event;

class ZipballEvent extends Event
{
    public const DOWNLOAD  = 'zipballDownload';

    public function __construct(
        private Package $package,
        private string $reference,
        private mixed $dist,
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
     * @return string
     */
    public function getReference(): string
    {
        return $this->reference;
    }

    /**
     * @return mixed
     */
    public function getDist(): mixed
    {
        return $this->dist;
    }

    public function setDist(mixed $dist)
    {
        $this->dist = $dist;
    }
}
