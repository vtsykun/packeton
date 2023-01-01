<?php

declare(strict_types=1);

namespace Packeton\Cron;

use Okvpn\Bundle\CronBundle\Model\OutputStamp;
use Okvpn\Bundle\CronBundle\Runner\ScheduleRunnerInterface;
use Packeton\Entity\Job;

class CronWorker
{
    public function __construct(private readonly ScheduleRunnerInterface $scheduleRunner)
    {
    }

    /**
     * @param Job $job
     * @return array
     */
    public function __invoke(Job $job): array
    {
        $payload = $job->getPayload();
        $envelope = unserialize($payload['envelope']);
        $envelope = $this->scheduleRunner->execute($envelope);
        $output = $envelope->get(OutputStamp::class) ?
            $envelope->get(OutputStamp::class)->getOutput() : 'No output';

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => $output
        ];
    }
}
