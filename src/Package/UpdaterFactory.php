<?php

declare(strict_types=1);

namespace Packeton\Package;

use Psr\Container\ContainerInterface;

class UpdaterFactory
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function createUpdater(string $repoType = null): UpdaterInterface
    {
        $repoType ??= 'vcs';
        return $this->container->get($repoType);
    }
}
