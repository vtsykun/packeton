<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Cron\Handler;

use Packagist\WebBundle\Webhook\HookBus;

class ScheduleHookHandler
{
    private $hookBus;

    public function __construct(HookBus $hookBus)
    {
        $this->hookBus = $hookBus;
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
