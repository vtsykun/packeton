<?php

namespace Packeton\Security\Acl;

use Packeton\Entity\Package;
use Packeton\Entity\Version;
use Packeton\Model\PacketonUserInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;

class PackagesAclVoter implements CacheableVoterInterface
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
    public function vote(TokenInterface $token, $object, array $attributes): int
    {
        if (!$object instanceof Package && !$object instanceof Version) {
            return self::ACCESS_ABSTAIN;
        }

        $user = $token->getUser();
        if (!$user instanceof PacketonUserInterface) {
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

    /**
     * {@inheritdoc}
     */
    public function supportsAttribute(string $attribute): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsType(string $subjectType): bool
    {
        return $subjectType === Package::class || $subjectType === Version::class;
    }
}
