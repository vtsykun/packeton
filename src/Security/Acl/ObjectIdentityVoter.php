<?php

declare(strict_types=1);

namespace Packeton\Security\Acl;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Group;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Packeton\Mirror\Model\ProxyRepositoryInterface as PRI;

class ObjectIdentityVoter implements VoterInterface
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    public function vote(TokenInterface $token, $subject, array $attributes): int
    {
        if (!$subject instanceof ObjectIdentity) {
            return self::ACCESS_ABSTAIN;
        }

        return match ($subject->getType()) {
            PRI::class => $this->checkProxyAccess($token, $subject->getIdentifier()),
            default => self::ACCESS_ABSTAIN
        };
    }

    private function checkProxyAccess(TokenInterface $token, $identifier): int
    {
        $user = $token->getUser();

        $allowed = $this->registry->getRepository(Group::class)->getAllowedProxies($user);

        return \in_array($identifier, $allowed, true) ? self::ACCESS_GRANTED : self::ACCESS_DENIED;
    }
}
