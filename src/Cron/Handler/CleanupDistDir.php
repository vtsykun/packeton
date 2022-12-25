<?php

declare(strict_types=1);

namespace Packeton\Cron\Handler;

use Packeton\Service\DistConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Remove unused mirrored package's *.zip archives
 */
class CleanupDistDir
{
    const DEFAULT_PERIOD = 60; // 60 days

    private $fs;
    private $distConfig;
    private $logger;

    public function __construct(DistConfig $distConfig, LoggerInterface $logger)
    {
        $this->fs = new Filesystem();
        $this->distConfig = $distConfig;
        $this->logger = $logger;
    }

    public function __invoke(array $arguments = [])
    {
        $keepPeriod = $arguments['period'] ?? self::DEFAULT_PERIOD;
        $expireDate = new \DateTime('now', new \DateTimeZone('UTC'));
        $expireDate->modify(sprintf('-%d days', $keepPeriod));

        if (null === $this->distConfig->getDistDir() || !$this->fs->exists($this->distConfig->getDistDir())) {
            return [];
        }

        $root = realpath($this->distConfig->getDistDir());
        $dir = new \RecursiveDirectoryIterator(
            $root,
            \FilesystemIterator::FOLLOW_SYMLINKS | \FilesystemIterator::SKIP_DOTS
        );

        $paths = [];
        $filter = new \RecursiveCallbackFilterIterator(
            $dir,
            function (\SplFileInfo $current) use (&$paths, $expireDate) {
                if (!$current->getRealPath()) {
                    return false;
                }
                if ($current->isFile() && preg_match('/[a-f0-9]{40}\.zip$/', $current->getFilename())) {
                    if ($current->getMTime() < $expireDate->getTimestamp()) {
                        $paths[] = $current->getRealPath();
                    }
                    return false;
                }
                if (is_dir($current->getPathname()) && 0 !== strpos($current->getPathname(), '.')) {
                    return true;
                }

                return false;
            }
        );

        $iterator = new \RecursiveIteratorIterator($filter);
        $iterator->rewind();
        if ($paths) {
            $this->logger->info(sprintf('Unused %s *.zip archives was found', count($paths)), ['paths' => $paths]);
        }

        foreach ($paths as $path) {
            try {
                $this->fs->remove($path);
            } catch (\Exception $exception) {
                $this->logger->warning(sprintf('Unable to delete the file "%s", cause %s', $path, $exception->getMessage()), ['path' => $path, 'e' => $exception]);
            }
        }

        return $paths;
    }
}
