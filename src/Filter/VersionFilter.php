<?php

declare(strict_types=1);

namespace Packeton\Filter;

use Composer\Package\PackageInterface;

class VersionFilter
{
    /**
     * @param PackageInterface[] $versions
     * @return PackageInterface[]
     */
    public function filterVersionsForOnlyMatchingRepoName(string $repoName, array $versions): array
    {
        $result = [];
        foreach ($versions as $version) {
            if ($version->getName() === $repoName) {
                $result[] = $version;
            }
        }
        return $result;
    }
}