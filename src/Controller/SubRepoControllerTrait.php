<?php

declare(strict_types=1);

namespace Packeton\Controller;

trait SubRepoControllerTrait
{
    protected function checkSubrepositoryAccess(string $name): bool
    {
        $packages = $this->subRepositoryHelper->allowedPackageNames();
        return $packages === null || in_array($name, $packages, true);
    }
}
