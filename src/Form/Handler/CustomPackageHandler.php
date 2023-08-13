<?php

declare(strict_types=1);

namespace Packeton\Form\Handler;

use Packeton\Composer\PackagistFactory;
use Packeton\Composer\Repository\CustomJsonRepository;
use Packeton\Entity\Package;

class CustomPackageHandler
{
    public function __construct(
        protected ?array $artifactPaths,
        protected PackagistFactory $packagistFactory,
    ) {
    }

    public function updatePackageUrl(Package $package): void
    {
        if ($package->customDriver === true) {
            return;
        }

        try {
            $repository = $this->packagistFactory->createRepository(
                $package->getRepository(),
                null,
                null,
                $package->getCredentials(),
                $package->getRepoConfig(),
            );

            $repository = $package->customDriver = $repository instanceof CustomJsonRepository ? $repository : null;
            if (!$repository instanceof CustomJsonRepository) {
                return;
            }

            $repository->getPackages();
        } catch (\Throwable $e) {
            $package->driverError = '['.get_class($e).'] '.$e->getMessage();
        }
    }
}
