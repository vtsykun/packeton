<?php

declare(strict_types=1);

namespace Packeton\Cron;

use Okvpn\Bundle\CronBundle\Model\CommandStamp;

class WorkerStamp implements CommandStamp
{
    public const DEFAULT_JOB_NAME = 'cron:execute';

    public function __construct(
        public array $config = [],
        public bool $asJob = false,
        public ?int $hash = null,
    ) {
        $this->asJob = $config['as_job'] ?? $this->asJob;
        $this->hash = $config['hash'] ?? $this->hash;
    }
}
