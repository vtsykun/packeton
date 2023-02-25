<?php

declare(strict_types=1);

namespace Packeton\EventListener;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Packeton\Entity\Group;
use Packeton\Entity\GroupAclPermission;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Entity\Version;
use Packeton\Model\ProviderManager;
use Packeton\Service\DistConfig;
use Symfony\Component\HttpFoundation\RequestStack;

class DoctrineListener
{
    private static $trackLastModifyClasses = [
        GroupAclPermission::class => true,
        Group::class => true,
        User::class => true,
        Version::class => true,
        Package::class => true,
    ];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ProviderManager $providerManager,
    ){
    }

    /**
     * @param Version $version
     * @param PostLoadEventArgs $event
     *
     * @return void
     */
    public function postLoad(Version $version, PostLoadEventArgs $event)
    {
        if (!$request = $this->requestStack->getMainRequest()) {
            return;
        }

        $dist = $version->getDist();
        if (isset($dist['url']) && \str_starts_with($dist['url'], DistConfig::HOSTNAME_PLACEHOLDER)) {
            $currentHost = $request->getSchemeAndHttpHost();

            $dist['url'] = \str_replace(DistConfig::HOSTNAME_PLACEHOLDER, $currentHost, $dist['url']);
            $version->distNormalized = $dist;
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        $changes = \array_merge(
            $uow->getScheduledEntityInsertions(),
            $uow->getScheduledEntityUpdates(),
            $uow->getScheduledEntityDeletions()
        );

        foreach ($changes as $object) {
            $class = ClassUtils::getClass($object);
            if (isset(self::$trackLastModifyClasses[$class])) {
                $this->providerManager->setRootLastModify();
                return;
            }
        }
    }
}
