<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook;

use Packagist\WebBundle\Entity\Job;
use Seld\Signal\SignalHandler;

class SenderWorker
{
    public function process(Job $job, SignalHandler $signal): array
    {
        return [];
    }
}
