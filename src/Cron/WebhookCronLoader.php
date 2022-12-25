<?php

declare(strict_types=1);

namespace Packeton\Cron;

use Doctrine\Common\Collections\Criteria;
use Doctrine\Persistence\ManagerRegistry;
use Okvpn\Bundle\CronBundle\Loader\ScheduleLoaderInterface;
use Okvpn\Bundle\CronBundle\Model\ScheduleEnvelope;
use Packeton\Cron\Handler\ScheduleHookHandler;
use Packeton\Entity\Webhook;
use Okvpn\Bundle\CronBundle\Model;

class WebhookCronLoader implements ScheduleLoaderInterface
{
    private $registry;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * {@inheritdoc}
     */
    public function getSchedules(array $options = []): iterable
    {
        if ('default' !== ($options['group'] ?? 'default')) {
            return;
        }

        $criteria = Criteria::create()
            ->andWhere(Criteria::expr()->neq("cron", null));
        $webhooks = $this->registry->getRepository(Webhook::class)
            ->findActive(null, [Webhook::HOOK_CRON], $criteria);

        foreach ($webhooks as $webhook) {
            yield new ScheduleEnvelope(
                ScheduleHookHandler::class,
                new Model\ScheduleStamp($webhook->getCron()),
                new Model\ArgumentsStamp(['webhookId' => $webhook->getId(), 'context' => []])
            );
        }
    }
}
