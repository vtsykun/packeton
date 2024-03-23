<?php declare(strict_types=1);

namespace Packeton\Service;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Entity\Job;

class Scheduler
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly ManagerRegistry $doctrine,
        private readonly JobPersister $persister,
    ) {
    }

    public function scheduleUpdate($packageOrId, bool $updateEqualRefs = false, bool $deleteBefore = false, ?\DateTimeInterface $executeAfter = null): Job
    {
        if ($packageOrId instanceof Package) {
            $packageOrId = $packageOrId->getId();
        } elseif (!is_numeric($packageOrId)) {
            throw new \UnexpectedValueException('Expected Package instance or int package id');
        }

        $packageOrId = (int) $packageOrId;
        $pendingJobId = $this->getPendingUpdateJob($packageOrId, $updateEqualRefs, $deleteBefore);
        if ($pendingJobId) {
            $pendingJob = $this->doctrine->getRepository(Job::class)->findOneBy(['id' => $pendingJobId]);

            if (!$pendingJob->getExecuteAfter() || $executeAfter) {
                return $pendingJob;
            }

            // pending job will somehow execute after the one we are scheduling so we mark it complete and schedule a new job to run immediately
            $pendingJob->start();
            $pendingJob->complete(['status' => Job::STATUS_COMPLETED, 'message' => 'Another job is attempting to schedule immediately for this package, aborting scheduled-for-later update']);
            $this->doctrine->getManager()->flush($pendingJob);
        }

        return $this->createJob('package:updates', ['id' => $packageOrId, 'update_equal_refs' => $updateEqualRefs, 'delete_before' => $deleteBefore], $packageOrId, $executeAfter);
    }

    public function publish(string $type, array|Job|null $job = null, ?int $packageId = null): Job
    {
        if ($job instanceof Job) {
            $job->setCreatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
            $job->setType($type);
        } else {
            $payload = $job ?: [];
            $job = new Job();
            $job->setType($type);
            $job->setPayload($payload);
            $job->setCreatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
        }

        $packageId ??= $job->getPackageId();
        $job->setPackageId($packageId);
        if (null !== $packageId && null !== ($id = $this->persister->getPendingJob($type, $packageId))) {
            $job->setId($id);
            $job->setStatus(Job::STATUS_COMPLETED);
            return $job;
        }

        $this->persister->persist($job);
        // trigger immediately if not scheduled for later
        if (!$job->getExecuteAfter()) {
            $this->redis->lpush('jobs', $job->getId());
        }

        return $job;
    }

    private function getPendingUpdateJob(int $packageId, $updateEqualRefs = false, $deleteBefore = false)
    {
        $result = $this->getConn()->fetchAssociative(
            'SELECT id, payload FROM job WHERE packageId = :package AND status = :status AND type = :type LIMIT 1',
            [
                'package' => $packageId,
                'type' => 'package:updates',
                'status' => Job::STATUS_QUEUED,
            ]
        );

        if ($result) {
            $payload = json_decode($result['payload'], true);
            if ($payload['update_equal_refs'] === $updateEqualRefs && $payload['delete_before'] === $deleteBefore) {
                return $result['id'];
            }
        }
    }

    /**
     * @return array [status => x, message => y]
     */
    public function getJobStatus(string $jobId): array
    {
        $data = $this->redis->get('job-'.$jobId);

        if ($data) {
            $data = json_decode($data, true);
            if (null === $data) {
                $result = $this->getConn()->fetchAssociative('SELECT id, result FROM job WHERE id = :id', ['id' => $jobId]);
                $data = $result ? json_decode($result['result'], true) : null;
            }
        }

        return $data ?: ['status' => 'running', 'message' => ''];
    }

    /**
     * @param  Job[]   $jobs
     * @return array[]
     */
    public function getJobsStatus(array $jobs): array
    {
        $results = [];

        foreach ($jobs as $job) {
            $jobId = $job->getId();
            $data = $this->redis->get('job-'.$jobId);

            if ($data) {
                $results[$jobId] = json_decode($data, true);
            } else {
                $results[$jobId] = ['status' => $job->getStatus()];
            }
        }

        return $results;
    }

    private function createJob(string $type, array $payload, ?int $packageId = null, $executeAfter = null): Job
    {
        $jobId = bin2hex(random_bytes(20));

        $job = new Job();
        $job->setId($jobId);
        $job->setType($type);
        $job->setPayload($payload);
        $job->setCreatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
        if ($packageId) {
            $job->setPackageId($packageId);
        }
        if ($executeAfter instanceof \DateTimeInterface) {
            $job->setExecuteAfter($executeAfter);
        }

        $this->persister->persist($job, false);

        // trigger immediately if not scheduled for later
        if (!$job->getExecuteAfter()) {
            $this->redis->lpush('jobs', $job->getId());
        }

        return $job;
    }

    private function getConn(): Connection
    {
        return $this->doctrine->getManager()->getConnection();
    }
}
