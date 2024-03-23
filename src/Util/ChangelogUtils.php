<?php

declare(strict_types=1);

namespace Packeton\Util;

use Packeton\Composer\PackagistFactory;
use Packeton\Entity\Package;

class ChangelogUtils
{
    private const VALID_TAG_REGEX = '#^[a-z0-9\.\-_]+$#i';

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
     * @param int $limit
     *
     * @return array
     */
    public function getChangelog(Package $package, string $fromVersion, string $toVersion, ?int $limit = null): array
    {
        $config = $this->factory->createConfig($package->getCredentials());

        // see GitDriver
        $repoDir = $config->get('cache-vcs-dir') . '/' . preg_replace('{[^a-z0-9.]}i', '-', $package->getRepository()) . '/';
        if (!is_dir($repoDir)) {
            return [];
        }
        if (!preg_match(self::VALID_TAG_REGEX, $fromVersion) || !preg_match(self::VALID_TAG_REGEX, $toVersion)) {
            return [];
        }

        $diff = escapeshellarg("$fromVersion..$toVersion");
        $cmd = "cd $repoDir; git log $diff --pretty=format:'- %B' --decorate=full --no-merges --date=short";
        if (null !== $limit) {
            $cmd .= " --max-count=$limit";
        }
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
