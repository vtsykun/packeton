<?php

declare(strict_types=1);

namespace Packeton\EventListener;

use Packeton\Entity\SubRepository;
use Packeton\Entity\User;
use Packeton\Model\PacketonUserInterface;
use Packeton\Security\Acl\SubRepoGrantVoter;
use Packeton\Service\SubRepositoryHelper;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

class SubRepositoryListener
{
    private static array $skipRoutes = [
        'login' => 1,
        'logout' => 1,
        'subrepository_switch' => 1,
        'subrepository_switch_root' => 1,
        'api_health' => 1,
    ];

    private static array $downloadRoutes = [
        'download_dist_package' => 1,
        'track_download_batch' => 1,
        'track_download' => 1,
    ];

    private static array $loginRoutes = [
        'login' => 1,
        'change_password' => 1,
        'request_pwd_check_email' => 1,
        'request_pwd_reset' => 1,
        'oauth_login' => 1,
        'oauth_check' => 1,
    ];

    public function __construct(
        protected SubRepositoryHelper $helper,
        protected TokenStorageInterface $tokenStorage,
        #[AutowireServiceClosure(service: 'twig')]
        protected $twig,
    ) {
    }

    #[AsEventListener(event: 'kernel.exception', priority: 10)]
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof AccessDeniedException) {
            return;
        }

        $request = $event->getRequest();
        $route = (string)$request->attributes->get('_route');
        $isPublicSubRepoHost = null !== ($subRepo = $this->helper->getByHost($request->getHost()))
            && $this->helper->isPublicAccess($subRepo)
            && null === $this->tokenStorage->getToken()?->getUser();

        if (!$isPublicSubRepoHost || isset(SubRepoGrantVoter::$rootRoutes[$route])) {
            return;
        }

        $repo = $this->helper->findSubRepo($subRepo);
        if ($route === 'home' && null !== $repo) {
            $response = $this->getTwig()->render('subrepository/public.html.twig', ['repo' => $repo]);
            $event->setResponse(new Response($response));
            $event->allowCustomResponseCode();
            return;
        }

        if (isset(self::$loginRoutes[$route])) {
            return;
        }

        $event->setResponse(new JsonResponse(['error' => 'Not found'], 404));
        $event->allowCustomResponseCode();
    }

    #[AsEventListener(event: 'kernel.request')]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string)$request->attributes->get('_route');
        if (isset(self::$skipRoutes[$route])) {
            return;
        }

        // Find by host -> session -> path url.
        $subRepo = $this->helper->getByHost($request->getHost());
        if ($request->hasSession(true)) {
            $subRepo = $request->getSession()->get('_sub_repo') ?: $subRepo;
        }

        $withSlug = false;
        if ($request->attributes->has('slug') && (SubRepoGrantVoter::$subRoutes[$route] ?? null)) {
            $subRepo = $this->helper->getBySlug($request->attributes->get('slug'));
            if (null === $subRepo) {
                throw new NotFoundHttpException("subrepository does not exists");
            }
            $withSlug = true;
        }

        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if ($subRepo) {
            $request->attributes->set('_sub_repo', $subRepo);
            $request->attributes->set('_sub_repo_type', !$withSlug ? SubRepository::AUTO_HOST : null);
        }

        if (isset(self::$downloadRoutes[$route])) {
            return;
        }

        if ($user instanceof PacketonUserInterface) {
            $allowedRepos = $user->getSubRepos() ?: [];
            $isAdmin = $user instanceof User ? $user->isAdmin() : in_array('ROLE_ADMIN', $token->getRoleNames());

            // Always allow root repository if it does not exist restriction.
            if (empty($allowedRepos) && null === $subRepo) {
                return;
            }

            $subRepo = $subRepo ?: 0;
            if (!$isAdmin && !in_array($subRepo, $allowedRepos, true)) {
                // select default sub repo
                if ($subRepo === 0 && $allowedRepos) {
                    $subRepo = min($allowedRepos);
                    $request->attributes->set('_sub_repo', $subRepo);
                    return;
                }

                if ($withSlug) {
                    throw new NotFoundHttpException("subrepository does not exists");
                } else {
                    throw new AccessDeniedHttpException("This subrepository is not allowed");
                }
            }
        }
    }

    private function getTwig(): Environment
    {
        return ($this->twig)();
    }
}
