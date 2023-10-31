<?php

declare(strict_types=1);

namespace Packeton\Repository;

use Doctrine\ORM\EntityRepository;
use Packeton\Entity\Job;

class JobRepository extends EntityRepository
{
    public function start(string $jobId): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        return 1 === $conn->executeStatement('UPDATE job SET status = :status, startedAt = :now WHERE id = :id AND startedAt IS NULL', [
            'id' => $jobId,
            'status' => Job::STATUS_STARTED,
            'now' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    public function markTimedOutJobs()
    {
        $conn = $this->getEntityManager()->getConnection();

        $conn->executeStatement('UPDATE job SET status = :newstatus WHERE status = :status AND startedAt < :timeout', [
            'status' => Job::STATUS_STARTED,
            'newstatus' => Job::STATUS_TIMEOUT,
            'timeout' => (new \DateTime('-30 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    public function getScheduledJobIds(): \Generator
    {
        $conn = $this->getEntityManager()->getConnection();

        $stmt = $conn->executeQuery('SELECT id FROM job WHERE status = :status AND (executeAfter IS NULL OR executeAfter <= :now) ORDER BY createdAt ASC', [
            'status' => Job::STATUS_QUEUED,
            'now' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);

        while ($row = $stmt->fetchOne()) {
            yield $row;
        }
    }

    /**
     * @param string $type
     * @param int $packageId
     * @param int $limit
     * @return Job[]
     */
    public function findJobsByType(string $type, $packageId = null, $limit = 25)
    {
        $jobs = [];
        $qb = $this->createQueryBuilder('j')
            ->resetDQLPart('select')
            ->select('j.id')
            ->where('j.type = :type')
            ->andWhere('j.createdAt IS NOT NULL')
            ->setMaxResults($limit)
            ->setParameter('type', $type)
            ->orderBy('j.createdAt', 'DESC');

        if ($packageId) {
            $qb->andWhere('j.packageId = :packageId')
                ->setParameter('packageId', $packageId);
        }

        // Memory limit sort error in MySql
        if ($jobsIds = $qb->getQuery()->getSingleColumnResult()) {
            $jobs = $this->createQueryBuilder('j')
                ->where('j.id IN (:ids)')
                ->setParameter('ids', $jobsIds)
                ->getQuery()->getResult();

            usort($jobs, fn(Job $j1, Job $j2) => $j2->getCreatedAt() <=> $j1->getCreatedAt());
        }

        return $jobs;
    }

    public function findLastJobByType(string $type, $packageId = null): ?Job
    {
        return $this->findJobsByType($type, $packageId, 1)[0] ?? null;
    }
}
