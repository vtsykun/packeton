<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Util;

use Composer\Config;
use Composer\Factory;
use Packagist\WebBundle\Composer\PackagistFactory;
use Packagist\WebBundle\Entity\Package;

class ChangelogUtils
{
    /**
     * @var PackagistFactory
     */
    protected $factory;

    /**
     * @param PackagistFactory $factory
     */
    public function __construct(PackagistFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * @param Package|object $package
     * @param string $fromVersion
     * @param string $toVersion
     *
     * @return array
     */
    public function getChangelog(Package $package, string $fromVersion, string $toVersion): array
    {
        $config = $this->factory->createConfig($package->getCredentials());

        // see GitDriver
        $repoDir = $config->get('cache-vcs-dir') . '/' . preg_replace('{[^a-z0-9.]}i', '-', $package->getRepository()) . '/';
        if (!is_dir($repoDir)) {
            return [];
        }

        $diff = escapeshellarg("$fromVersion..$toVersion");
        $cmd = "cd $repoDir; git log $diff --pretty=format:'- %B' --decorate=full --no-merges --date=short";
        if (!$output = shell_exec($cmd)) {
            return [];
        }

        $commitMessages = [];
        $changeLogs = explode("\n\n", $output);
        foreach ($changeLogs as $changeLog) {
            if ($changeLog) {
                $commitMessages[] = $changeLog;
            }
        }

        $commitMessages = array_values(array_unique($commitMessages));
        return array_map([$this, 'trim'], $commitMessages);
    }

    private function trim(string $value): string
    {
        return trim($value, " -\t");
    }
}
