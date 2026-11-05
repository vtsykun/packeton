<?php

declare(strict_types=1);

namespace Packeton\Security\Acl;

use Packeton\Entity\Group;
use Packeton\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;

class GroupOwnerVoter implements CacheableVoterInterface
{
    public const EDIT = 'EDIT';
    public const ADD_PACKAGE = 'ADD_PACKAGE';
    public const REMOVE_PACKAGE = 'REMOVE_PACKAGE';

    /**
     * {@inheritdoc}
     */
    public function vote(TokenInterface $token, $object, array $attributes): int
    {
        if (!$object instanceof Group) {
            return self::ACCESS_ABSTAIN;
        }

        $matchedAttributes = array_intersect($attributes, [self::EDIT, self::ADD_PACKAGE, self::REMOVE_PACKAGE]);
        if (empty($matchedAttributes)) {
            return self::ACCESS_ABSTAIN;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return self::ACCESS_DENIED;
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return self::ACCESS_GRANTED;
        }

        /** @var Group $group */
        $group = $object;

        return $group->hasOwner($user) ? self::ACCESS_GRANTED : self::ACCESS_DENIED;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsAttribute(string $attribute): bool
    {
        return in_array($attribute, [self::EDIT, self::ADD_PACKAGE, self::REMOVE_PACKAGE], true);
    }

    /**
     * {@inheritdoc}
     */
    public function supportsType(string $subjectType): bool
    {
        return $subjectType === Group::class;
    }
}
