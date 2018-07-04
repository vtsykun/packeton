<?php

namespace Packagist\WebBundle\Package;

use Composer\Package\Archiver\ArchiveManager as ComposerArchiveManager;
use Composer\Package\PackageInterface;

class ArchiveManager extends ComposerArchiveManager
{
    /**
     * Generate a distinct filename for a particular version of a package.
     *
     * @param PackageInterface $package The package to get a name for
     *
     * @return string A filename without an extension
     */
    public function getPackageFilename(PackageInterface $package)
    {
        //use a commit hash for git
        if (preg_match('{^[a-f0-9]{40}$}', $package->getSourceReference())) {
            return $package->getSourceReference();
        }

        return parent::getPackageFilename($package);
    }
}
