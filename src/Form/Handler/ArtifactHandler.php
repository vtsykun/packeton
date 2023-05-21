<?php

declare(strict_types=1);

namespace Packeton\Form\Handler;

use Packeton\Composer\PackagistFactory;
use Packeton\Composer\Repository\ArtifactRepository;
use Packeton\Entity\Package;
use Packeton\Util\PacketonUtils;

class ArtifactHandler
{
    public function __construct(
        protected ?array $artifactPaths,
        protected PackagistFactory $packagistFactory,
    ) {
    }

    public function updatePackageUrl(Package $package): void
    {
        if ($package->getRepositoryPath() === null && $package->getRepository() === null) {
            $package->setRepositoryPath(null);
        }

        if ($package->artifactDriver === true) {
            return;
        }

        try {
            $this->checkAllowedPath($package->getRepositoryPath());

            $repository = $this->packagistFactory->createRepository(
                $package->getRepository(),
                null,
                null,
                $package->getCredentials(),
                $package->getRepoConfig(),
            );

            $repository = $package->artifactDriver = $repository instanceof ArtifactRepository ? $repository : null;
            if (!$repository instanceof ArtifactRepository) {
                return;
            }

            $probe = $repository->getPackages()[0] ?? null;
            if ($probe && null === $package->getName()) {
                $package->setName($probe->getName());
            }
        } catch (\Throwable $e) {
            $package->driverError = '['.get_class($e).'] '.$e->getMessage();
        }
    }

    public function checkAllowedPath(?string $path): void
    {
        if (null === $path) {
            return;
        }

        $path = PacketonUtils::normalizePath($path);

        if (null === PacketonUtils::filterAllowedPaths($path, $this->artifactPaths ?: [])) {
            throw new \InvalidArgumentException("The path $path is not allowed");
        }
    }
}
