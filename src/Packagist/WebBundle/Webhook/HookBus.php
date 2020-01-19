<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook;

use Packagist\WebBundle\Entity\Webhook;
use Packagist\WebBundle\Service\JobScheduler;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class HookBus
{
    private $normalizer;
    private $jobScheduler;

    public function __construct(NormalizerInterface $normalizer, JobScheduler $jobScheduler)
    {
        $this->normalizer = $normalizer;
        $this->jobScheduler = $jobScheduler;
    }

    /**
     * @param Webhook|int $webhook
     * @param mixed $context
     * @return \Packagist\WebBundle\Entity\Job
     */
    public function dispatch($context, $webhook)
    {
        $context = $this->normalizer->normalize($context, 'packagist_job');
        return $this->jobScheduler->publish('webhook:send', [
            'context' => $context,
            'webhook' => is_numeric($webhook) ? $webhook : $webhook->getId(),
        ]);
    }
}
