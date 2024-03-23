<?php

declare(strict_types=1);

namespace Packeton\Webhook;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\AsWorker;
use Packeton\Entity\Job;
use Packeton\Entity\Webhook;
use Packeton\Service\JobScheduler;
use Packeton\Webhook\Twig\WebhookContext;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AsWorker('webhook:send')]
class SenderWorker
{
    public const MAX_NESTING_LEVEL = 3;

    /** @var DenormalizerInterface|NormalizerInterface  */
    private $denormalizer;
    private $registry;
    private $executor;
    private $jobScheduler;
    private $maxNestingLevel;
    private $logger;

    public function __construct(DenormalizerInterface $denormalizer, ManagerRegistry $registry, HookRequestExecutor $executor, JobScheduler $jobScheduler, ?LoggerInterface $logger = null, $maxNestingLevel = self::MAX_NESTING_LEVEL)
    {
        $this->denormalizer = $denormalizer;
        $this->registry = $registry;
        $this->executor = $executor;
        $this->jobScheduler = $jobScheduler;
        $this->logger = $logger;
        $this->maxNestingLevel = max($maxNestingLevel, 2);
    }

    public function __invoke(Job $job): array
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

        if ($nestingLevel >= $this->maxNestingLevel) {
            throw new \RuntimeException(sprintf('Maximum webhook nesting level of %s reached', $this->maxNestingLevel));
        }

        $context = $this->denormalizer->denormalize($payload['context'] ?? [], 'context', 'packagist_job');
        $runtimeContext = new WebhookContext();
        $this->executor->setContext($runtimeContext);
        $this->executor->setLogger($logger = new WebhookLogger(LogLevel::NOTICE));
        $logger->setWrapperLogger($this->logger);

        if (isset($payload['parentJob'])) {
            $parentJob = $this->registry->getRepository(Job::class)->find($payload['parentJob']);
            if ($parentJob instanceof Job) {
                try {
                    $response = array_map(HookResponse::fromArray(...), $parentJob->getResult()['response'] ?? []);
                    $context['parentResponse'] = reset($response);
                } catch (\Throwable $e) {}
            }
        }

        try {
            $response = $this->executor->executeWebhook($webhook, $context);
            foreach ($response as $item) {
                $item->setLogs($logger->getLogs());
            }
        } finally {
            $this->executor->setContext(null);
            $this->executor->setLogger(new NullLogger());
            $logger->clearLogs();
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
