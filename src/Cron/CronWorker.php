<?php

declare(strict_types=1);

namespace Packeton\Cron;

use Okvpn\Bundle\CronBundle\Model\OutputStamp;
use Okvpn\Bundle\CronBundle\Runner\ScheduleRunnerInterface;
use Packeton\Entity\Job;

class CronWorker
{
    private $scheduleRunner;

    public function __construct(ScheduleRunnerInterface $scheduleRunner)
    {
        $this->scheduleRunner = $scheduleRunner;
    }

    /**
     * @param Job $job
     * @return array
     */
    public function process(Job $job): array
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
