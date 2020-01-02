<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Entity\Webhook;
use Packagist\WebBundle\Event\UpdaterErrorEvent;
use Packagist\WebBundle\Event\UpdaterEvent;
use Packagist\WebBundle\Service\JobScheduler;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class HookListener
{
    private $registry;
    private $normalizer;
    private $jobScheduler;

    public function __construct(ManagerRegistry $registry, NormalizerInterface $normalizer, JobScheduler $jobScheduler)
    {
        $this->registry = $registry;
        $this->normalizer = $normalizer;
        $this->jobScheduler = $jobScheduler;
    }

    /**
     * @param UpdaterEvent $event
     */
    public function onPackageRefresh(UpdaterEvent $event): void
    {
        // Skip triggers when used force flag
        if ($event->getFlags() !== 0) {
            return;
        }

        $webhooks = $this->registry->getRepository(Webhook::class)
            ->findActive($event->getPackage()->getName());
        $info =  $this->getStabilityInfo(
            array_merge($event->getDeleted(), $event->getUpdated(), $event->getCreated())
        );
        foreach ($webhooks as $webhook) {
            if ($versions = $this->matchVersions($info, $event, $webhook)) {
                $context = [
                    'package' => $event->getPackage(),
                    'versions' => $versions,
                    'event' => Webhook::HOOK_RL_NEW
                ];

                $context = $this->normalizer->normalize($context, 'packagist_job');
                $this->jobScheduler->publish('webhook:send', [
                    'context' => $context,
                    'webhook' => $webhook->getId(),
                ]);
            }
        }
    }

    /**
     * @param UpdaterEvent $event
     */
    public function onPackagePersist(UpdaterEvent $event): void
    {
        $webhooks = $this->registry->getRepository(Webhook::class)
            ->findActive($event->getPackage()->getName(), [Webhook::HOOK_REPO_NEW]);

        foreach ($webhooks as $webhook) {
            $context = [
                'package' => $event->getPackage(),
                'event' => Webhook::HOOK_REPO_NEW
            ];

            $context = $this->normalizer->normalize($context, 'packagist_job');
            $this->jobScheduler->publish('webhook:send', [
                'context' => $context,
                'webhook' => $webhook->getId(),
            ]);
        }
    }

    /**
     * @param UpdaterErrorEvent $event
     */
    public function onPackageError(UpdaterErrorEvent $event): void
    {
        $webhooks = $this->registry->getRepository(Webhook::class)
            ->findActive($event->getPackage()->getName(), [Webhook::HOOK_REPO_FAILED]);

        foreach ($webhooks as $webhook) {
            $context = [
                'package' => $event->getPackage(),
                'event' => Webhook::HOOK_REPO_FAILED,
                'output' => $event->getOutput(),
                'exception_message' => $event->getException()->getMessage(),
                'exception_class' => get_class($event->getException()->getMessage())
            ];

            $context = $this->normalizer->normalize($context, 'packagist_job');
            $this->jobScheduler->publish('webhook:send', [
                'context' => $context,
                'webhook' => $webhook->getId(),
            ]);
        }
    }

    private function getStabilityInfo(array $versions): array
    {
        $versions = $this->registry->getRepository(Version::class)
            ->createQueryBuilder('v')
            ->resetDQLPart('select')
            ->select(['v.id', 'v.version', 'v.development'])
            ->where('v.id IN (:versionIds)')
            ->setParameter('versionIds', $versions)
            ->getQuery()
            ->getResult();

        $versions = $versions ? array_combine(array_column($versions, 'id'), $versions) : [];
        return $versions;
    }

    private function matchVersions(array $versionInfo, UpdaterEvent $event, Webhook $hook)
    {
        $updatedVersions = $newVersions = $removeVersions = [];

        if ($hook->matchAnyEvents(Webhook::HOOK_RL_NEW, Webhook::HOOK_PUSH_NEW)) {
            $isDev = $hook->matchAllEvents(Webhook::HOOK_PUSH_NEW);
            $newVersions = array_filter($event->getCreated(), function ($v) use ($versionInfo, $isDev) {
                return true === $isDev || (isset($versionInfo[$v]) && $versionInfo[$v]['development'] === false);
            });
        }

        if ($hook->matchAnyEvents(Webhook::HOOK_RL_UPDATE, Webhook::HOOK_PUSH_UPDATE)) {
            $isDev = $hook->matchAllEvents(Webhook::HOOK_PUSH_UPDATE);
            $updatedVersions = array_filter($event->getUpdated(), function ($v) use ($versionInfo, $isDev) {
                return true === $isDev || (isset($versionInfo[$v]) && $versionInfo[$v]['development'] === false);
            });
        }

        if ($hook->matchAnyEvents(Webhook::HOOK_RL_DELETE)) {
            $removeVersions = array_filter($event->getDeleted(), function ($v) use ($versionInfo) {
                return (isset($versionInfo[$v]) && $versionInfo[$v]['development'] === false);
            });
        }

        $repo = $this->registry->getRepository(Version::class);
        $versions = [];
        foreach (array_merge($newVersions, $updatedVersions) as $id) {
            if ($regex = $hook->getVersionRestriction()) {
                if (isset($versionInfo[$id]['version']) && !preg_match($regex, $versionInfo[$id]['version'])) {
                    continue;
                }
            }
            $versions[] = ['@entity_class' => Version::class, '@id' => $id];
        }
        foreach ($removeVersions as $id) {
            $version = $repo->find($id);
            if ($version instanceof Version) {
                if ($hook->getVersionRestriction() && preg_match($hook->getVersionRestriction(), $version->getVersion())) {
                    continue;
                }
                $versions[] = $version->toArray();
            }
        }

        return $versions;
    }
}
