<?php

namespace Packeton\Security\Acl;

use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\Constraint;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Group;
use Packeton\Entity\Package;
use Packeton\Entity\Version;
use Packeton\Model\PacketonUserInterface as PUI;

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
     * @param PUI $user
     * @param Package $package
     *
     * @return bool
     */
    public function isGrantedAccessForPackage(PUI $user, Package $package)
    {
        $version = $this->getVersions($user, $package);
        return \count($version) > 0;
    }

    public function isGrantedAccessForAllVersions(PUI $user, Package $package)
    {
        $versionConstraints = $this->getVersions($user, $package);
        foreach ($versionConstraints as $constraint) {
            if ($constraint === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check that customer/user can download this version
     *
     * @param PUI $user
     * @param Version $version
     *
     * @return bool
     */
    public function isGrantedAccessForVersion(PUI $user, Version $version)
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

    private function getVersions(PUI $user, Package $package)
    {
        $hash = $user->getUserIdentifier() . '$' . $package->getId();
        if (isset($this->versionCache[$hash])) {
            return $this->versionCache[$hash];
        }

        $version = $this->registry->getRepository(Group::class)
            ->getAllowedVersionByPackage($user, $package);
        return $this->versionCache[$hash] = $version;
    }
}
