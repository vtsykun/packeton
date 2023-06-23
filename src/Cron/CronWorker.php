<?php

declare(strict_types=1);

namespace Packeton\Cron;

use Okvpn\Bundle\CronBundle\Model\OutputStamp;
use Okvpn\Bundle\CronBundle\Runner\ScheduleRunnerInterface;
use Packeton\Attribute\AsWorker;
use Packeton\Entity\Job;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Monolog\Handler\ConsoleHandler;

#[AsWorker('cron:execute')]
class CronWorker
{
    public function __construct(
        private readonly ScheduleRunnerInterface $scheduleRunner,
        private readonly LoggerInterface $logger,
        private readonly ?ConsoleHandler $consoleHandler = null,
    ) {}

    /**
     * @param Job $job
     * @return array
     */
    public function __invoke(Job $job): array
    {
        $payload = $job->getPayload();

        $envelope = unserialize($payload['envelope']);
        $backupOutput = $this->getConsoleOutput();

        try {
            $envelope = $this->scheduleRunner->execute($envelope);
        } finally {
            if (null !== $backupOutput && $this->consoleHandler) {
                $this->consoleHandler->setOutput($backupOutput);
            }
        }

        $output = $envelope->get(OutputStamp::class) ?
            $envelope->get(OutputStamp::class)->getOutput() : 'No output';

        $this->logger->info("Executed cron job. Result: " . ($msg = (is_array($output) ? json_encode($output) : $output)));

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => $msg
        ];
    }

    private function getConsoleOutput()
    {
        if (null === $this->consoleHandler) {
            return null;
        }

        $reflect = new \ReflectionClass(ConsoleHandler::class);
        $prop = $reflect->getProperty('output');
        $prop->setAccessible(true);

        return $prop->getValue($this->consoleHandler);
    }

}
