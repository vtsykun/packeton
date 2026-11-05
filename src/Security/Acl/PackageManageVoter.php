<?php

declare(strict_types=1);

namespace Packeton\Security\Acl;

use Packeton\Entity\Package;
use Packeton\Entity\User;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;

class PackageManageVoter implements CacheableVoterInterface
{
    public const MANAGE = 'MANAGE';

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function vote(TokenInterface $token, $object, array $attributes): int
    {
        if (!in_array(self::MANAGE, $attributes, true) || !$object instanceof Package) {
            return self::ACCESS_ABSTAIN;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return self::ACCESS_DENIED;
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return self::ACCESS_GRANTED;
        }

        if (!$this->parameterBag->get('packeton.allow_maintainer_group_creation')) {
            return self::ACCESS_DENIED;
        }

        /** @var Package $package */
        $package = $object;

        foreach ($user->getOwnedGroups() as $group) {
            foreach ($group->getAclPermissions() as $permission) {
                if ($permission->getPackage()?->getId() === $package->getId()) {
                    return self::ACCESS_GRANTED;
                }
            }
        }

        return self::ACCESS_DENIED;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsAttribute(string $attribute): bool
    {
        return $attribute === self::MANAGE;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsType(string $subjectType): bool
    {
        return $subjectType === Package::class;
    }
}
