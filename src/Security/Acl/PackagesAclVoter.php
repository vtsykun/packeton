<?php

namespace Packeton\Security\Acl;

use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Entity\Version;
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
    public function vote(TokenInterface $token, $object, array $attributes)
    {
        if (!$object instanceof Package && !$object instanceof Version) {
            return self::ACCESS_ABSTAIN;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return self::ACCESS_ABSTAIN;
        }

        if ($object instanceof Package) {
            if (!$this->checker->isGrantedAccessForPackage($user, $object)) {
                return self::ACCESS_DENIED;
            }
            if (in_array('VIEW_ALL_VERSION', $attributes) && !$this->checker->isGrantedAccessForAllVersions($user, $object)) {
                return self::ACCESS_DENIED;
            }
        }
        if ($object instanceof Version && $this->checker->isGrantedAccessForVersion($user, $object) === false) {
            return self::ACCESS_DENIED;
        }

        return self::ACCESS_GRANTED;
    }
}
