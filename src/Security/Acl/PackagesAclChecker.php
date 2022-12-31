<?php

namespace Packeton\Security\Acl;

use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\Constraint;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Group;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Entity\Version;

class PackagesAclChecker
{
    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var VersionParser
     */
    private $parser;

    /**
     * @var array
     */
    private $versionCache = [];

    /**
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
        $this->parser = new VersionParser();
    }

    /**
     * Check that customer/user can see and download this package
     *
     * @param User $user
     * @param Package $package
     *
     * @return bool
     */
    public function isGrantedAccessForPackage(User $user, Package $package)
    {
        $version = $this->getVersions($user, $package);
        return \count($version) > 0;
    }

    /**
     * Check that customer/user can download this version
     *
     * @param User $user
     * @param Version $version
     *
     * @return bool
     */
    public function isGrantedAccessForVersion(User $user, Version $version)
    {
        if ($user->getExpiredUpdatesAt() && $user->getExpiredUpdatesAt() < $version->getReleasedAt()) {
            return false;
        }

        $versions = $this->getVersions($user, $version->getPackage());

        foreach ($versions as $constraint) {
            if ($constraint === null) {
                return true;
            }
            $constraint = $this->parser->parseConstraints($constraint);
            $pkgConstraint = new Constraint('==', $version->getNormalizedVersion());
            if ($constraint->matches($pkgConstraint) === true) {
                return true;
            }
        }
        return false;
    }

    private function getVersions(User $user, Package $package)
    {
        $hash = $user->getId() . '$' . $package->getId();
        if (isset($this->versionCache[$hash])) {
            return $this->versionCache[$hash];
        }

        $version = $this->registry->getRepository(Group::class)
            ->getAllowedVersionByPackage($user, $package);
        return $this->versionCache[$hash] = $version;
    }
}
