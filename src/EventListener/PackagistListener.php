<?php

declare(strict_types=1);

namespace Packeton\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Packeton\Entity\Group;
use Packeton\Entity\GroupAclPermission;
use Packeton\Entity\Package;
use Packeton\Entity\SubRepository;
use Packeton\Entity\User;
use Packeton\Entity\Version;
use Packeton\Event\FormHandlerEvent;
use Packeton\Model\ProviderManager;
use Packeton\Service\DistConfig;
use Packeton\Service\SubRepositoryHelper;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: 'formHandler')]
#[AsDoctrineListener(event: 'onFlush')]
#[AsEntityListener(event: 'postLoad', entity: 'Packeton\Entity\Version')]
class PackagistListener
{
    private static $trackLastModifyClasses = [
        GroupAclPermission::class => true,
        Group::class => true,
        User::class => true,
        Version::class => true,
        Package::class => true,
        SubRepository::class => true,
    ];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ProviderManager $providerManager,
        private readonly SubRepositoryHelper $subRepositoryHelper,
    ) {
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
            $slug = $this->subRepositoryHelper->getCurrentSlug();
            $replacement = null !== $slug ? $currentHost . '/' . $slug : $currentHost;

            $dist['url'] = \str_replace(DistConfig::HOSTNAME_PLACEHOLDER, $replacement, $dist['url']);
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
            if (isset(static::$trackLastModifyClasses[$class])) {
                $this->providerManager->setRootLastModify();
                return;
            }
        }
    }

    public function onFormHandler(FormHandlerEvent $event): void
    {
        if (isset(static::$trackLastModifyClasses[$event->getEntityClass()])) {
            $this->providerManager->setRootLastModify();
        }
    }
}
