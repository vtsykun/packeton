<?php

declare(strict_types=1);

namespace Packeton\Security\Acl;

use Packeton\Entity\SshCredentials;
use Packeton\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;

class CredentialManageVoter implements CacheableVoterInterface
{
    public const MANAGE = 'MANAGE';

    /**
     * {@inheritdoc}
     */
    public function vote(TokenInterface $token, $object, array $attributes): int
    {
        if (!in_array(self::MANAGE, $attributes, true) || !$object instanceof SshCredentials) {
            return self::ACCESS_ABSTAIN;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return self::ACCESS_DENIED;
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return self::ACCESS_GRANTED;
        }

        /** @var SshCredentials $credential */
        $credential = $object;

        $owner = $credential->getOwner();
        if ($owner !== null && $owner->getId() === $user->getId()) {
            return self::ACCESS_GRANTED;
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
        return $subjectType === SshCredentials::class;
    }
}
