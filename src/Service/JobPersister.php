<?php

declare(strict_types=1);

namespace Packeton\Service;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Job;

class JobPersister
{
    private $registry;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function persist(Job $job): void
    {
        $isExists = false;
        if (null !== $job->getId()) {
            $isExists = (bool) $this->registry->getRepository(Job::class)
                ->createQueryBuilder('j')
                ->resetDQLPart('select')
                ->select('j.id')
                ->where('j.id = :id')
                ->setParameter('id', $job->getId())
                ->getQuery()
                ->getResult();
        } else {
            $job->setId(\bin2hex(\random_bytes(20)));
        }

        $data = [
            'id' => $job->getId(),
            'payload' => $job->getPayload(),
            'status' => $job->getStatus(),
            'createdAt' => $job->getCreatedAt(),
            'startedAt' => $job->getStartedAt(),
            'completedAt' => $job->getCompletedAt(),
            'executeAfter' => $job->getExecuteAfter(),
            'type' => $job->getType(),
            'packageId' => $job->getPackageId(),
            'result' => $job->getResult(),
        ];
        $types = [
            'payload' => 'json',
            'createdAt' => 'datetime',
            'executeAfter' => 'datetime',
            'completedAt' => 'datetime',
            'startedAt' => 'datetime',
            'result' => 'json'
        ];

        if (true === $isExists) {
            $this->getConn()->update('job', $data, ['id' => $job->getId()], $types);
        } else {
            $this->getConn()->insert('job', $data, $types);
        }
    }

    public function getPendingJob(string $type, int $hash): ?string
    {
        $result = $this->getConn()->fetchAssociative(
            'SELECT id FROM job WHERE packageId = :package AND status = :status AND type = :type LIMIT 1',
            [
                'package' => $hash,
                'type' => $type,
                'status' => Job::STATUS_QUEUED,
            ]
        );

        if ($result) {
            return $result['id'];
        }

        return null;
    }

    /**
     * @return \Doctrine\DBAL\Connection
     */
    private function getConn()
    {
        $em = $this->registry->getManager();
        return $em->getConnection();
    }
}
