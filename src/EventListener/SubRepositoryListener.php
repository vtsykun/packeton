<?php

declare(strict_types=1);

namespace Packeton\EventListener;

use Packeton\Entity\User;
use Packeton\Model\PacketonUserInterface;
use Packeton\Security\Acl\SubRepoGrantVoter;
use Packeton\Service\SubRepositoryHelper;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class SubRepositoryListener
{
    public function __construct(
        protected SubRepositoryHelper $helper,
        protected TokenStorageInterface $tokenStorage
    ) {
    }

    #[AsEventListener(event: 'kernel.request')]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        // Find by host -> session -> path url.
        $subRepo = $this->helper->getByHost($request->getHost());
        if ($request->hasSession(true)) {
            $subRepo = $request->getSession()->get('_sub_repo') ?: $subRepo;
        }

        if ($request->attributes->has('slug') && (SubRepoGrantVoter::$subRoutes[(string)$request->attributes->get('_route')] ?? null)) {
            $subRepo = $this->helper->getBySlug($request->attributes->get('slug'));
            if (null === $subRepo) {
                throw new NotFoundHttpException("subrepository does not exists");
            }
        }

        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if ($subRepo) {
            $request->attributes->set('_sub_repo', $subRepo);
        }

        if ($user instanceof PacketonUserInterface) {
            $allowedRepos = $user->getSubRepos() ?: [];
            $isAdmin = $user instanceof User ? $user->isAdmin() : in_array('ROLE_ADMIN', $token->getRoleNames());

            // For BC. Always allow root repository.
            if (empty($allowedRepos) && null === $subRepo) {
                return;
            }

            $subRepo = $subRepo ?: 0;
            if (!$isAdmin && !in_array($subRepo, $allowedRepos, true)) {
                throw new AccessDeniedHttpException("This subrepository is not allowed");
            }
        }
    }
}
