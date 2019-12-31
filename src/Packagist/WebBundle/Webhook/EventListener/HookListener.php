<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Packagist\WebBundle\Entity\Webhook;
use Packagist\WebBundle\Event\UpdaterEvent;

class HookListener
{
    private $registry;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function onPackageRefresh(UpdaterEvent $event): void
    {
        // Skip triggers when used force flag
        if ($event->getFlags() !== 0) {
            return;
        }

        $webhooks = $this->registry->getRepository(Webhook::class)
            ->findActive($event->getPackage()->getName());
    }
}
