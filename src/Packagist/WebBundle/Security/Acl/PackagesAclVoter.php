<?php

namespace Packagist\WebBundle\Security\Acl;

use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\User;
use Packagist\WebBundle\Entity\Version;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class PackagesAclVoter implements VoterInterface
{
    /**
     * @var PackagesAclChecker
     */
    private $checker;

    public function __construct(PackagesAclChecker $checker)
    {
        $this->checker = $checker;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsAttribute($attribute)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsClass($class)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function vote(TokenInterface $token, $object, array $attributes)
    {
        if (!$object instanceof Package && !$object instanceof Version) {
            return self::ACCESS_ABSTAIN;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return self::ACCESS_DENIED;
        }
        if ($object instanceof Package && $this->checker->isGrantedAccessForPackage($user, $object) === false) {
            return self::ACCESS_DENIED;
        }
        if ($object instanceof Version && $this->checker->isGrantedAccessForVersion($user, $object) === false) {
            return self::ACCESS_DENIED;
        }

        return self::ACCESS_ABSTAIN;
    }
}
