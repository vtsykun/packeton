<?php

namespace Packeton\Security\Acl;

use Packeton\Entity\OwnerAwareInterface;
use Packeton\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;

class OwnerVoter implements CacheableVoterInterface
{
    /**
     * {@inheritdoc}
     */
    public function vote(TokenInterface $token, $object, array $attributes): int
    {
        if (!$object instanceof OwnerAwareInterface || !in_array('VIEW', $attributes)) {
            return self::ACCESS_ABSTAIN;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return $object->getVisibility() === OwnerAwareInterface::GLOBAL_VISIBLE || null === $object->getVisibility()
                ? self::ACCESS_GRANTED : self::ACCESS_DENIED;
        }

        if ($object->getVisibility() === OwnerAwareInterface::USER_VISIBLE && $object->getOwner() && $object->getOwner()->getId() !== $user->getId()) {
            return self::ACCESS_DENIED;
        }
        if ($object->getVisibility() === OwnerAwareInterface::STRICT_VISIBLE && $object->getOwner()?->getId() !== $user->getId()) {
            return self::ACCESS_DENIED;
        }

        return self::ACCESS_GRANTED;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsAttribute(string $attribute): bool
    {
        return $attribute === 'VIEW';
    }

    /**
     * {@inheritdoc}
     */
    public function supportsType(string $subjectType): bool
    {
        return true;
    }
}
