<?php

declare(strict_types=1);

namespace Packeton\Mirror\Decorator;

use Packeton\Mirror\Exception\ApproveRestrictException;
use Packeton\Mirror\Exception\MetadataNotFoundException;
use Packeton\Mirror\Model\ApprovalRepoInterface;
use Packeton\Mirror\Model\JsonMetadata;
use Packeton\Mirror\Model\StrictProxyRepositoryInterface as RPI;
use Packeton\Mirror\RemoteProxyRepository;
use Packeton\Mirror\Utils\IncludeV1ApiMetadata;

/**
 * Filter by available_packages, available_package_patterns
 */
class ProxyRepositoryACLDecorator extends AbstractProxyRepositoryDecorator
{
    public function __construct(
        protected RPI $repository,
        protected ApprovalRepoInterface $approval,
        protected ?RemoteProxyRepository $remote = null,
        protected array $availablePackages = [],
        protected array $availablePackagePatterns = [],
        protected array $excludedPackages = [],
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function rootMetadata(): JsonMetadata
    {
        $metadata = $this->repository->rootMetadata();
        if ($this->approval->requireApprove()) {
            $approved = $this->approval->getApproved();
            $metadata->setOption('available_packages', $approved);

            if (null !== $this->remote) {
                $metadata->setOption('includes', function () use ($approved) {
                    [$includes] = IncludeV1ApiMetadata::buildInclude($approved, $this->remote);
                    return $includes;
                });
            }
        }

        return $metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function findProviderMetadata(string $nameOrUri): JsonMetadata
    {
        if (\str_starts_with($nameOrUri, 'include-packeton/all$') && null !== $this->remote) {
            $approved = $this->approval->getApproved();
            [$includes, $content] = IncludeV1ApiMetadata::buildInclude($approved, $this->remote);
            if (isset($includes[$nameOrUri])) {
                return new JsonMetadata($content);
            }
        }

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
        } else if (\in_array($package, $this->excludedPackages)) {
            throw new ApproveRestrictException(
                "The package '$package' has been already registered in the your private repository. " .
                "3-rd party mirrored packages always is canonical, so do not allowed use it if your already add private package by same name"
            );
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
