<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Cron;

use Okvpn\Bundle\CronBundle\Model\CommandStamp;

class WorkerStamp implements CommandStamp
{
    public const JOB_NAME = 'cron:execute';
}
