<?php

declare(strict_types=1);

namespace Packeton\Cron;

use Okvpn\Bundle\CronBundle\Middleware\MiddlewareEngineInterface;
use Okvpn\Bundle\CronBundle\Middleware\StackInterface;
use Okvpn\Bundle\CronBundle\Model\ScheduleEnvelope;
use Packeton\Service\JobScheduler;

class WorkerMiddleware implements MiddlewareEngineInterface
{
    private $jobScheduler;

    public function __construct(JobScheduler $jobScheduler)
    {
        $this->jobScheduler = $jobScheduler;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(ScheduleEnvelope $envelope, StackInterface $stack): ScheduleEnvelope
    {
        if (!$envelope->get(WorkerStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $envelopeData = \serialize($envelope->without(WorkerStamp::class));
        $this->jobScheduler->publish(WorkerStamp::JOB_NAME, [
            'envelope' => $envelopeData
        ]);

        return $stack->end()->handle($envelope, $stack);
    }
}
