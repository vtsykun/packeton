<?php

declare(strict_types=1);

namespace Packeton\EventListener;

use Packeton\Event\PackageEvent;
use Packeton\Event\UpdaterEvent;
use Packeton\Integrations\IntegrationRegistry;
use Packeton\Integrations\Model\AppUtils;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class IntegrationListener
{
    public function __construct(
        protected IntegrationRegistry $integrations,
        protected LoggerInterface $logger
    ) {
    }

    #[AsEventListener(event: 'packageCreate')]
    public function onPackageCreate(PackageEvent $event): void
    {
        $package = $event->getPackage();
        if (null === ($oauth = $package->getIntegration()) || empty($package->getExternalRef())) {
            return;
        }

        try {
            $app = $this->integrations->findApp($oauth->getAlias());
            if ($app->getConfig()->disableRepoHooks()) {
                return;
            }

            $info = $app->addHook($oauth, $package->getExternalRef());
            if ($info['status'] ?? false) {
                $package->setAutoUpdated(true);
            }
        } catch (\Throwable $e) {
            $info = ['status' => false, 'error' => AppUtils::castError($e, $app ?? null)];
        }

        $package->setWebhookInfo($info);
    }

    #[AsEventListener(event: 'packageRemove')]
    public function onPackageDelete(UpdaterEvent $event): void
    {
        $package = $event->getPackage();
        if (null === ($oauth = $package->getIntegration()) || empty($package->getExternalRef())) {
            return;
        }

        try {
            $app = $this->integrations->findApp($oauth->getAlias(), false);
            $app->removeHook($oauth, $package->getExternalRef(), $package->getWebhookInfo());
        } catch (\Throwable $e) {
        }
    }
}
