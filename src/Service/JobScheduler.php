<?php

declare(strict_types=1);

namespace Packeton\Service;

use Packeton\Entity\Job;

class JobScheduler
{
    private $persister;
    private $redis;

    public function __construct(\Redis $redis, JobPersister $persister)
    {
        $this->persister = $persister;
        $this->redis = $redis;
    }

    /**
     * Return job id
     *
     * @param string $type
     * @param array|Job|null $job
     * @param int|null $hash
     *
     * @return Job
     */
    public function publish(string $type, array|Job|null $job = null, ?int $hash = null): Job
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

        $job->setPackageId($hash);
        if (null !== $hash && null !== $this->persister->getPendingJob($type, $hash)) {
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
}
