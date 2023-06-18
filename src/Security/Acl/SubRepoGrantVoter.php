<?php

declare(strict_types=1);

namespace Packeton\Security\Acl;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;

class SubRepoGrantVoter implements CacheableVoterInterface
{
    public static $subRoutes = [
        'root_packages_slug' => 1,
        'root_providers_slug' => 1,
    ];

    /**
     * {@inheritdoc}
     */
    public function vote(TokenInterface $token, mixed $subject, array $attributes)
    {
        if ($subject instanceof Request && isset(self::$subRoutes[$subject->attributes->get('_route')])) {
            return $token->getUser() ? self::ACCESS_GRANTED : self::ACCESS_ABSTAIN;
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
}
