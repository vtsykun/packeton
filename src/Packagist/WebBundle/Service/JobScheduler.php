<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Service;

use Packagist\WebBundle\Entity\Job;
use Predis\Client as RedisClient;

class JobScheduler
{
    private $persister;
    private $redis;

    public function __construct(RedisClient $redis, JobPersister $persister)
    {
        $this->persister = $persister;
        $this->redis = $redis;
    }

    /**
     * Return job id
     *
     * @param string $type
     * @param array|Job|null $job
     * @return Job
     */
    public function publish(string $type, $job = null): Job
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

        $this->persister->persist($job);
        // trigger immediately if not scheduled for later
        if (!$job->getExecuteAfter()) {
            $this->redis->lpush('jobs', $job->getId());
        }

        return $job;
    }
}
