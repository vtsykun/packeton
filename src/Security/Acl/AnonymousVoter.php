<?php

namespace Packeton\Security\Acl;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class AnonymousVoter implements VoterInterface
{
    public function __construct(
        private readonly bool $isAnonymousAccess,
        private readonly bool $isAnonymousArchiveAccess,
        private readonly bool $isAnonymousMirror,
    ){}

    public function vote(TokenInterface $token, $subject, array $attributes): int
    {
        if (true === $this->isAnonymousAccess &&
            (in_array('ROLE_FULL_CUSTOMER', $attributes, true) || in_array('PACKETON_PUBLIC', $attributes, true))
        ) {
            return self::ACCESS_GRANTED;
        }

        if (true === $this->isAnonymousArchiveAccess && in_array('PACKETON_ARCHIVE_PUBLIC', $attributes, true)) {
            return self::ACCESS_GRANTED;
        }

        if (true === $this->isAnonymousMirror && in_array('PACKETON_MIRROR_PUBLIC', $attributes, true)) {
            return self::ACCESS_GRANTED;
        }

        return self::ACCESS_ABSTAIN;
    }
}
