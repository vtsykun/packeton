<?php

declare(strict_types=1);

namespace Packeton\Cron;

use Okvpn\Bundle\CronBundle\Model\CommandStamp;

class WorkerStamp implements CommandStamp
{
    public const DEFAULT_JOB_NAME = 'cron:execute';

    public function __construct(
        public readonly bool $asJob = false,
        public readonly ?int $hash = null,
    ) {
    }
}
