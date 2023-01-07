<?php

namespace Packeton\Security\Acl;

use Packeton\Entity\OwnerAwareInterface;
use Packeton\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class OwnerVoter implements VoterInterface
{
    /**
     * {@inheritdoc}
     */
    public function vote(TokenInterface $token, $object, array $attributes)
    {
        if (!$object instanceof OwnerAwareInterface) {
            return self::ACCESS_ABSTAIN;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return self::ACCESS_DENIED;
        }

        if ($object->getVisibility() === OwnerAwareInterface::USER_VISIBLE && $object->getOwner() && $object->getOwner()->getId() !== $user->getId()) {
            return self::ACCESS_DENIED;
        }
        if ($object->getVisibility() === OwnerAwareInterface::STRICT_VISIBLE && $object->getOwner()?->getId() !== $user->getId()) {
            return self::ACCESS_DENIED;
        }

        return self::ACCESS_GRANTED;
    }
}
