<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Event;

use Packagist\WebBundle\Entity\Package;
use Symfony\Component\EventDispatcher\Event;

class UpdaterErrorEvent extends Event
{
    public const PACKAGE_ERROR  = 'packageError';

    private $exception;
    private $flags;
    private $output;
    private $package;

    public function __construct(Package $package, \Throwable $exception, $output = null, $flags = 0)
    {
        $this->package = $package;
        $this->exception = $exception;
        $this->output = $output;
        $this->flags = $flags;
    }

    /**
     * @return \Throwable
     */
    public function getException(): \Throwable
    {
        return $this->exception;
    }

    /**
     * @return int
     */
    public function getFlags()
    {
        return $this->flags;
    }

    /**
     * @return null
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * @return Package
     */
    public function getPackage(): Package
    {
        return $this->package;
    }
}
