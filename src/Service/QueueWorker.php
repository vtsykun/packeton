<?php

declare(strict_types=1);

namespace Packeton\Service;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Repository\JobRepository;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Packeton\Entity\Job;
use Seld\Signal\SignalHandler;

class QueueWorker
{
    private $processedJobs = 0;
    private $logResetter;

    public function __construct(
        private readonly \Redis $redis,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
        private readonly JobPersister $persister,
        private readonly ContainerInterface $workersContainer
    ) {
        $this->logResetter = new LogResetter($logger);
    }

    /**
     * @param string|int $count
     */
    public function processMessages(int $count)
    {
        $signal = SignalHandler::create(null, $this->logger);

        $this->logger->info('Waiting for new messages');

        $nextTimedoutJobCheck = $this->checkForTimedoutJobs();
        $nextScheduledJobCheck = $this->checkForScheduledJobs($signal);

        while ($this->processedJobs++ < $count) {
            if ($signal->isTriggered()) {
                $this->logger->debug('Signal received, aborting');
                break;
            }

            $now = time();
            if ($nextTimedoutJobCheck <= $now) {
                $nextTimedoutJobCheck = $this->checkForTimedoutJobs();
            }
            if ($nextScheduledJobCheck <= $now) {
                $nextScheduledJobCheck = $this->checkForScheduledJobs($signal);
            }

            try {
                $result = $this->redis->brpop('jobs', 1);
            } catch (\Throwable $throwable) {
                sleep(1);
                if ($signal->isTriggered()) {
                    $this->logger->debug('Signal received, aborting');
                    return;
                }
                throw $throwable;
            }

            if (!$result) {
                $this->logger->debug('No message in queue');
                continue;
            }

            $jobId = $result[1];
            $this->process($jobId, $signal);
        }
    }

    private function checkForTimedoutJobs(): int
    {
        $this->doctrine->getManager()->getRepository(Job::class)->markTimedOutJobs();

        // check for timed out jobs every 20 min at least
        return time() + 1200;
    }

    private function checkForScheduledJobs(SignalHandler $signal): int
    {
        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(Job::class);

        foreach ($repo->getScheduledJobIds() as $jobId) {
            if ($this->process($jobId, $signal)) {
                $this->processedJobs++;
            }
        }

        // check for scheduled jobs every 1 minutes at least
        return time() + 60;
    }

    /**
     * Calls the configured processor with the job and a callback that must be called to mark the job as processed
     * @inheritDoc
     */
    private function process(string $jobId, SignalHandler $signal): bool
    {
        $em = $this->doctrine->getManager();
        /** @var JobRepository|object $repo */
        $repo = $em->getRepository(Job::class);
        if (!$repo->start($jobId)) {
            // race condition, some other worker caught the job first, aborting
            return false;
        }

        /** @var Job $job */
        $job = $repo->findOneById($jobId);

        $this->logger->pushProcessor(function ($record) use ($job) {
            $record['extra']['job-id'] = $job->getId();

            return $record;
        });

        $processor = $this->workersContainer->get($job->getType());

        // clears/resets all fingers-crossed handlers to avoid dumping info messages that happened between two job executions
        $this->logResetter->reset();

        $this->logger->debug('Processing ' . $job->getType() . ' job', ['job' => $job->getPayload()]);

        try {
            $result = $processor($job, $signal);
        } catch (\Throwable $e) {
            $result = [
                'status' => Job::STATUS_ERRORED,
                'message' => 'An unexpected failure occurred',
                'exception' => $e,
            ];
        }

        // If an exception is thrown during a transaction the EntityManager is closed
        // and we won't be able to update the job or handle future jobs
        if (!$this->doctrine->getManager()->isOpen()) {
            $this->doctrine->resetManager();
        }

        // refetch objects in case the EM was reset during the job run
        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(Job::class);

        if ($result['status'] === Job::STATUS_RESCHEDULE) {
            $job->reschedule($result['after']);
            $this->persister->persist($job);

            // reset logger
            $this->logResetter->reset();
            $this->logger->popProcessor();
            $em->clear();

            return true;
        }

        if (!isset($result['message']) || !isset($result['status'])) {
            throw new \LogicException('$result must be an array with at least status and message keys');
        }

        if (!in_array($result['status'], [Job::STATUS_COMPLETED, Job::STATUS_FAILED, Job::STATUS_ERRORED, Job::STATUS_PACKAGE_GONE, Job::STATUS_PACKAGE_DELETED], true)) {
            throw new \LogicException('$result[\'status\'] must be one of '.Job::STATUS_COMPLETED.' or '.Job::STATUS_FAILED.', '.$result['status'].' given');
        }

        if (isset($result['exception'])) {
            $result['exceptionMsg'] = $result['exception']->getMessage();
            $result['exceptionClass'] = get_class($result['exception']);
            unset($result['exception']);
        }

        $job = $repo->find($jobId);
        $job->complete($result);

        $this->redis->setex('job-'.$job->getId(), 600, json_encode($result));

        $this->persister->persist($job);
        $em->clear();

        if ($result['status'] === Job::STATUS_FAILED) {
            $this->logger->warning('Job '.$job->getId().' failed', $result);
        } elseif ($result['status'] === Job::STATUS_ERRORED) {
            $this->logger->error('Job '.$job->getId().' errored', $result);
        }

        // clears/resets all fingers-crossed handlers so that if one triggers it doesn't dump the entire debug log for all processed
        $this->logResetter->reset();

        $this->logger->popProcessor();

        return true;
    }
}
