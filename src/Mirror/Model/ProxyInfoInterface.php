<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

interface ProxyInfoInterface
{
    /**
     * Get configuration.
     *
     * @return ProxyOptions
     */
    public function getConfig(): ProxyOptions;
}
