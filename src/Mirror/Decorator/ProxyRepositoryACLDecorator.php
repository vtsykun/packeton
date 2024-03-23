<?php

declare(strict_types=1);

namespace Packeton\Mirror\Decorator;

use Packeton\Mirror\Exception\ApproveRestrictException;
use Packeton\Mirror\Model\JsonMetadata;
use Packeton\Mirror\Model\StrictProxyRepositoryInterface as RPI;
use Packeton\Mirror\RemoteProxyRepository;
use Packeton\Mirror\Service\RemotePackagesManager;
use Packeton\Mirror\Utils\ApiMetadataUtils;
use Packeton\Model\PatTokenUser;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Filter by available_packages, available_package_patterns
 */
class ProxyRepositoryACLDecorator extends AbstractProxyRepositoryDecorator
{
    public function __construct(
        protected RPI $repository,
        protected RemotePackagesManager $rpm,
        protected TokenStorageInterface $tokenStorage,
        protected ?RemoteProxyRepository $remote = null,
        protected array $availablePackages = [],
        protected array $availablePackagePatterns = [],
        protected array $excludedPackages = [],
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function rootMetadata(?int $modifiedSince = null): JsonMetadata
    {
        $metadata = $this->repository->rootMetadata($modifiedSince);
        $metadata->setOptions($this->rpm->getSettings());

        if ($this->rpm->requireApprove()) {
            $approved = $this->rpm->getApproved();
            $metadata->setOption('available_packages', $approved);

            if (null !== $this->remote) {
                $metadata->setOption('includes', function () use ($approved) {
                    [$includes] = ApiMetadataUtils::buildIncludesV1($approved, $this->remote);
                    return $includes;
                });
            }

            $root = $metadata->decodeJson();
            $root['providers-lazy-url'] = true;
            unset($root['providers'], $root['provider-includes'], $root['packages'], $root['providers-url']);

            $metadata = $metadata->withContent($root);
        }

        return $metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function findProviderMetadata(string $nameOrUri, ?int $modifiedSince = null): JsonMetadata
    {
        if (\str_starts_with($nameOrUri, 'include-packeton/all$') && null !== $this->remote) {
            $approved = $this->rpm->getApproved();
            [$includes, $content] = ApiMetadataUtils::buildIncludesV1($approved, $this->remote);
            if (isset($includes[$nameOrUri])) {
                return new JsonMetadata($content);
            }
        }

        return $this->repository->findProviderMetadata($nameOrUri, $modifiedSince);
    }

    /**
     * {@inheritdoc}
     */
    public function findPackageMetadata(string $nameOrUri, ?int $modifiedSince = null): JsonMetadata
    {
        [$package, ] = \explode('$', $nameOrUri);
        $user = $this->tokenStorage->getToken()?->getUser();
        if ($user instanceof PatTokenUser && !$user->hasScore("mirror:all")) {
            if (!$this->rpm->isEnabled($package)) {
                throw new ApproveRestrictException("This is CI read-only token, the package $package is not enabled.");
            }
        }

        $metadata = $this->repository->findPackageMetadata($nameOrUri, $modifiedSince);

        if ($this->rpm->requireApprove()) {
            if (!$this->rpm->isApproved($package)) {
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
