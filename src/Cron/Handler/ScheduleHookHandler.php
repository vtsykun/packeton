<?php

declare(strict_types=1);

namespace Packeton\Cron\Handler;

use Okvpn\Bundle\CronBundle\CronServiceInterface;
use Packeton\Webhook\HookBus;

class ScheduleHookHandler implements CronServiceInterface
{
    public function __construct(private readonly HookBus $hookBus)
    {
    }

    /**
     * @param array $arguments
     */
    public function __invoke(array $arguments = [])
    {
        if (isset($arguments['webhookId'])) {
            $this->hookBus->dispatch($arguments['context'] ?? null, $arguments['webhookId']);
        }
    }
}
