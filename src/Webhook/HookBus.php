<?php

declare(strict_types=1);

namespace Packeton\Webhook;

use Doctrine\Common\Collections\Criteria;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Job;
use Packeton\Entity\Webhook;
use Packeton\Service\JobScheduler;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class HookBus
{
    public function __construct(
        protected NormalizerInterface $normalizer,
        protected JobScheduler $jobScheduler,
        protected ManagerRegistry $registry,
    ) {
    }

    public function dispatch(mixed $context, Webhook|int|string $webhook): Job
    {
        $context = $this->normalizer->normalize($context, 'packagist_job');
        return $this->jobScheduler->publish('webhook:send', [
            'context' => $context,
            'webhook' => is_numeric($webhook) ? $webhook : $webhook->getId(),
        ]);
    }

    public function dispatchAll(mixed $context, string $event, ?string $package = null, ?Criteria $criteria = null): array
    {
        $jobs = [];
        $webhooks = $this->getWebhooks($event, $package, $criteria);
        foreach ($webhooks as $webhook) {
            $jobs[] = $this->dispatch($context, $webhook)->getId();
        }

        return $jobs;
    }

    public function hasActive(string $event, ?string $package = null, ?Criteria $criteria = null): bool
    {
        return (bool) $this->getWebhooks($event, $package, $criteria);
    }

    protected function getWebhooks(string $event, ?string $package = null, ?Criteria $criteria = null)
    {
        return $this->registry->getRepository(Webhook::class)->findActive($package, [$event], $criteria);
    }
}
