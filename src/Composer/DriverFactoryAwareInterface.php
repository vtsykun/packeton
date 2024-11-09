<?php

declare(strict_types=1);

namespace Packeton\Composer;

interface DriverFactoryAwareInterface
{
    public function setDriverFactory(VcsDriverFactory $factory): void;
}
