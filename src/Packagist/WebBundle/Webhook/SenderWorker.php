<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook;

use Doctrine\Persistence\ManagerRegistry;
use Packagist\WebBundle\Entity\Job;
use Packagist\WebBundle\Entity\Webhook;
use Packagist\WebBundle\Service\JobScheduler;
use Packagist\WebBundle\Webhook\Twig\WebhookContext;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class SenderWorker
{
    private const MAX_NESTING_LEVEL = 3;

    /** @var DenormalizerInterface|NormalizerInterface  */
    private $denormalizer;
    private $registry;
    private $executor;
    private $jobScheduler;

    public function __construct(DenormalizerInterface $denormalizer, ManagerRegistry $registry, HookRequestExecutor $executor, JobScheduler $jobScheduler)
    {
        $this->denormalizer = $denormalizer;
        $this->registry = $registry;
        $this->executor = $executor;
        $this->jobScheduler = $jobScheduler;
    }

    public function process(Job $job): array
    {
        $payload = $job->getPayload();
        $webhook = $this->registry->getRepository(Webhook::class)
            ->find($payload['webhook']);

        $nestingLevel = (int) ($payload['nestingLevel'] ?? 0);
        if (!$webhook instanceof Webhook) {
            return [
                'message' => 'Webhook do not exists',
                'status' => Job::STATUS_COMPLETED
            ];
        }

        if ($nestingLevel >= self::MAX_NESTING_LEVEL) {
            throw new \RuntimeException('Maximum webhook nesting level of 3 reached');
        }

        $context = $this->denormalizer->denormalize($payload['context'] ?? [], 'context', 'packagist_job');
        $runtimeContext = new WebhookContext();
        $this->executor->setContext($runtimeContext);
        if (isset($payload['parentJob'])) {
            $parentJob = $this->registry->getRepository(Job::class)->find($payload['parentJob']);
            if ($parentJob instanceof Job) {
                try {
                    $response = array_map(
                        HookResponse::class.'::fromArray',
                        $parentJob->getResult()['response'] ?? []
                    );
                    $context['parentResponse'] = reset($response);
                } catch (\Throwable $e) {}
            }
        }

        try {
            $response = $this->executor->executeWebhook($webhook, $context);
        } finally {
            $this->executor->setContext(null);
        }

        $job->setPackageId($webhook->getId());
        if (isset($runtimeContext[WebhookContext::CHILD_WEBHOOK])) {
            $this->processChildWebhook($job, $webhook, $nestingLevel, $runtimeContext[WebhookContext::CHILD_WEBHOOK]);
        }

        $isSuccess = !array_filter($response, function (HookResponse $response) {
            return false === $response->isSuccess();
        });

        return [
            'message' => 'Ok',
            'status' => $isSuccess ? Job::STATUS_COMPLETED : Job::STATUS_FAILED,
            'response' => $response
        ];
    }

    private function processChildWebhook(Job $job, Webhook $parent, int $nestingLevel,  array $child): void
    {
        /** @var Webhook $hook */
        foreach ($child as list($hook, $context)) {
            if (null !== $hook->getOwner() && $hook->getVisibility() === Webhook::USER_VISIBLE && $hook->getOwner() !== $parent->getOwner()) {
                continue;
            }

            unset($context['webhook'], $context['__url_placeholder_context']);
            $context = $this->denormalizer->normalize($context, 'packagist_job');
            $this->jobScheduler->publish('webhook:send', [
                'context' => $context,
                'webhook' => $hook->getId(),
                'nestingLevel' => $nestingLevel+1,
                'parentJob' => $job->getId()
            ]);
        }
    }
}
