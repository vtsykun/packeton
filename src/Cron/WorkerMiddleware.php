<?php

declare(strict_types=1);

namespace Packeton\Cron;

use Okvpn\Bundle\CronBundle\Middleware\MiddlewareEngineInterface;
use Okvpn\Bundle\CronBundle\Middleware\StackInterface;
use Okvpn\Bundle\CronBundle\Model\ArgumentsStamp;
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

        /** @var WorkerStamp $stamp */
        $stamp = $envelope->get(WorkerStamp::class);
        if (true === $stamp->asJob) {
            $args = $envelope->get(ArgumentsStamp::class) ? $envelope->get(ArgumentsStamp::class)->getArguments() : [];
            $this->jobScheduler->publish($envelope->getCommand(), $args, $stamp->hash);
            return $stack->end()->handle($envelope, $stack);
        }

        $envelopeData = \serialize($envelope->without(WorkerStamp::class));

        $this->jobScheduler->publish(WorkerStamp::DEFAULT_JOB_NAME, [
            'envelope' => $envelopeData
        ]);

        return $stack->end()->handle($envelope, $stack);
    }
}
