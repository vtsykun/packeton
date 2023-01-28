<?php

declare(strict_types=1);

namespace Packeton\Cron\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Okvpn\Bundle\CronBundle\Attribute\AsCron;
use Packeton\Entity\Job;
use Psr\Log\LoggerInterface;

/**
 * Cron command to cleanup jobs storage
 */
#[AsCron('49 0 * * *')]
class CleanupJobStorage
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(): array
    {
        $keepPeriod = $this->selectKeepPeriod($count);
        $expireDate = new \DateTime('now', new \DateTimeZone('UTC'));
        $expireDate->modify(\sprintf('-%d days', $keepPeriod));

        $rowCount = $this->registry->getRepository(Job::class)
            ->createQueryBuilder('j')
            ->delete()
            ->where('j.createdAt < :createdAt')
            ->setParameter('createdAt', $expireDate)
            ->getQuery()
            ->execute();

        $this->logger->info(\sprintf('Removed %s jobs from storage, since: %s, jobs count: %s', $rowCount, $expireDate->format('c'), $count));

        return [
            'since' => $expireDate->format('c'),
            'rows' => $rowCount,
            'count' => $count
        ];
    }

    protected function selectKeepPeriod(&$count = null): int
    {
        $count = $this->registry->getRepository(Job::class)
            ->createQueryBuilder('j')
            ->resetDQLPart('select')
            ->select('COUNT(j.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return match ($count) {
            $count > 60000 => 2,
            $count > 40000 => 5,
            $count > 25000 => 10,
            $count > 10000 => 21,
            default => 60,
        };
    }
}
