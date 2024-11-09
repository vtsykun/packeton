<?php

declare(strict_types=1);

namespace Packeton\Security\Acl;

use Packeton\Service\SubRepositoryHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;

class SubRepoGrantVoter implements CacheableVoterInterface
{
    public static array $subRoutes = [
        'root_packages_slug' => 1,
        'root_providers_slug' => 1,
        'root_package_slug' => 1,
        'root_package_v2_slug' => 1,
        'download_dist_package_slug' => 1,
        'track_download_batch_slug' => 1,
        'track_download_slug' => 1,
        'sub_repository_home' => 1,
    ];

    public static array $rootRoutes = [
        'root_packages' => 1,
        'root_providers' => 1,
        'root_package' => 1,
        'root_package_v2' => 1,
        'download_dist_package' => 1,
        'track_download_batch' => 1,
        'track_download' => 1,
    ];

    public function __construct(
        private readonly SubRepositoryHelper $helper
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function vote(TokenInterface $token, mixed $request, array $attributes): int
    {
        if (!$request instanceof Request) {
            return self::ACCESS_ABSTAIN;
        }

        $route = $request->attributes->get('_route');
        if (isset(self::$subRoutes[$route])
            || (isset(self::$rootRoutes[$route]) && null !== $this->helper->getByHost($request->getHost()))
        ) {
            return $token->getUser() || $this->isPublicSubRepo($request) ? self::ACCESS_GRANTED : self::ACCESS_ABSTAIN;
        }

        return self::ACCESS_ABSTAIN;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsAttribute(string $attribute): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsType(string $subjectType): bool
    {
        return $subjectType === Request::class;
    }

    private function isPublicSubRepo(Request $request): bool
    {
        if (null !== ($subRepo = $this->getSubRepoForRequest($request))) {
            return $this->helper->isPublicAccess($subRepo);
        }
        return false;
    }

    private function getSubRepoForRequest(Request $request): ?int
    {
        $route = (string) $request->attributes->get('_route');
        if ($request->attributes->has('slug') && (SubRepoGrantVoter::$subRoutes[$route] ?? null)) {
            return $this->helper->getBySlug($request->attributes->get('slug'));
        }

        return $this->helper->getByHost($request->getHost());
    }
}
