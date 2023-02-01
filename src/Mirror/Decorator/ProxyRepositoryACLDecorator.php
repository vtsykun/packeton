<?php

declare(strict_types=1);

namespace Packeton\Mirror\Decorator;

use Packeton\Mirror\Exception\ApproveRestrictException;
use Packeton\Mirror\Model\ApprovalRepoInterface;
use Packeton\Mirror\Model\JsonMetadata;
use Packeton\Mirror\Model\StrictProxyRepositoryInterface as RPI;

/**
 * Filter by available_packages, available_package_patterns
 */
class ProxyRepositoryACLDecorator extends AbstractProxyRepositoryDecorator
{
    public function __construct(
        protected RPI $repository,
        protected ApprovalRepoInterface $approval,
        protected array $availablePackages = [],
        protected array $availablePackagePatterns = [],
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function rootMetadata(): JsonMetadata
    {
        return $this->repository->rootMetadata();
    }

    /**
     * {@inheritdoc}
     */
    public function findProviderMetadata(string $nameOrUri): JsonMetadata
    {
        return $this->repository->findProviderMetadata($nameOrUri);
    }

    /**
     * {@inheritdoc}
     */
    public function findPackageMetadata(string $nameOrUri): JsonMetadata
    {
        $metadata = $this->repository->findPackageMetadata($nameOrUri);

        [$package, ] = \explode('$', $nameOrUri);
        if ($this->approval->requireApprove()) {
            $approved = $this->approval->getApproved();
            if (!\in_array($package, $approved)) {
                throw new ApproveRestrictException("This package $package has not yet been approved by an administrator.");
            }

            return $metadata;
        }

        if ($this->availablePackages && !\in_array($package, $this->availablePackages, true)) {
            throw new ApproveRestrictException("This package $package was restricted by available packages config");
        }

        if ($this->availablePackagePatterns) {
            foreach ($this->availablePackagePatterns as $pattern) {
                if (\fnmatch($pattern, $package)) {
                    return $metadata;
                }
            }
            throw new ApproveRestrictException("This package $package was restricted by available patterns config");
        }

        return $metadata;
    }
}
