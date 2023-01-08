<?php

namespace Packeton\Security\Acl;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class AnonymousVoter implements VoterInterface
{
    public function __construct(private readonly bool $isAnonymousAccess)
    {
    }

    public function vote(TokenInterface $token, $subject, array $attributes): int
    {
        if (true === $this->isAnonymousAccess && in_array('ROLE_FULL_CUSTOMER', $attributes, true)) {
            return self::ACCESS_GRANTED;
        }

        return self::ACCESS_ABSTAIN;
    }
}
