<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook;

use Doctrine\Persistence\ManagerRegistry;
use Packagist\WebBundle\Entity\Job;
use Packagist\WebBundle\Entity\Webhook;
use Seld\Signal\SignalHandler;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class SenderWorker
{
    private $denormalizer;
    private $registry;
    private $executor;

    public function __construct(DenormalizerInterface $denormalizer, ManagerRegistry $registry, HookRequestExecutor $executor)
    {
        $this->denormalizer = $denormalizer;
        $this->registry = $registry;
        $this->executor = $executor;
    }

    public function process(Job $job, SignalHandler $signal): array
    {
        $payload = $job->getPayload();
        $webhook = $this->registry->getRepository(Webhook::class)
            ->find($payload['webhook']);

        if (!$webhook instanceof Webhook) {
            return [
                'message' => 'Webhook do not exists',
                'status' => Job::STATUS_COMPLETED
            ];
        }

        $context = $this->denormalizer->denormalize($payload['context'] ?? [], 'context', 'packagist_job');
        $response = $this->executor->executeWebhook($webhook, $context);
        $job->setPackageId($webhook->getId());

        $isSuccess = !array_filter($response, function (HookResponse $response) {
            return false === $response->isSuccess();
        });

        return [
            'message' => 'Ok',
            'status' => $isSuccess ? Job::STATUS_COMPLETED : Job::STATUS_FAILED,
            'response' => $response
        ];
    }
}
