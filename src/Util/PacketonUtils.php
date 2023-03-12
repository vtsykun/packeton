<?php

declare(strict_types=1);

namespace Packeton\Util;

use Composer\Package\PackageInterface;

class PacketonUtils
{
    public static function sort(array $packages): array
    {
        usort($packages, function (PackageInterface $a, PackageInterface $b) {
            $aVersion = $a->getVersion();
            $bVersion = $b->getVersion();
            if ($aVersion === '9999999-dev' || str_starts_with($aVersion, 'dev-')) {
                $aVersion = 'dev';
            }
            if ($bVersion === '9999999-dev' || str_starts_with($bVersion, 'dev-')) {
                $bVersion = 'dev';
            }
            $aIsDev = $aVersion === 'dev' || str_ends_with($aVersion, '-dev');
            $bIsDev = $bVersion === 'dev' || str_ends_with($bVersion, '-dev');

            // push dev versions to the end
            if ($aIsDev !== $bIsDev) {
                return $aIsDev ? 1 : -1;
            }

            // equal versions are sorted by date
            if ($aVersion === $bVersion) {
                return $a->getReleaseDate() > $b->getReleaseDate() ? 1 : -1;
            }

            // the rest is sorted by version
            return version_compare($aVersion, $bVersion);
        });

        return $packages;
    }
}
