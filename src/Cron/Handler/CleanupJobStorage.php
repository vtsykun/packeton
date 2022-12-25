<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Cron\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Packagist\WebBundle\Entity\Job;
use Psr\Log\LoggerInterface;

/**
 * Cron command to cleanup jobs storage
 */
class CleanupJobStorage
{
    private $registry;
    private $logger;

    public function __construct(ManagerRegistry $registry, LoggerInterface $logger)
    {
        $this->registry = $registry;
        $this->logger = $logger;
    }

    public function __invoke()
    {
        $keepPeriod = $this->selectKeepPeriod($count);
        $expireDate = new \DateTime('now', new \DateTimeZone('UTC'));
        $expireDate->modify(sprintf('-%d days', $keepPeriod));

        $rowCount = $this->registry->getRepository(Job::class)
            ->createQueryBuilder('j')
            ->delete()
            ->where('j.createdAt < :createdAt')
            ->setParameter('createdAt', $expireDate)
            ->getQuery()
            ->execute();

        $this->logger->info(sprintf('Removed %s jobs from storage, since: %s, jobs count: %s', $rowCount, $expireDate->format('c'), $count));

        return [
            'since' => $expireDate->format('c'),
            'rows' => $rowCount,
            'count' => $count
        ];
    }

    protected function selectKeepPeriod(&$count = null)
    {
        $count = $this->registry->getRepository(Job::class)
            ->createQueryBuilder('j')
            ->resetDQLPart('select')
            ->select('COUNT(j.id)')
            ->getQuery()
            ->getSingleScalarResult();

        switch ($count) {
            case $count > 60000:
                return 2;
            case $count > 40000:
                return 5;
            case $count > 25000:
                return 10;
            case $count > 10000:
                return 21;
        }

        return 60;
    }
}
