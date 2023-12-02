<?php

declare(strict_types=1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packeton\Controller;

use Doctrine\ORM\NoResultException;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Entity\ApiToken;
use Packeton\Entity\Package;
use Packeton\Entity\SshCredentials;
use Packeton\Entity\User;
use Packeton\Form\Handler\UserFormHandler;
use Packeton\Form\Type\ApiTokenType;
use Packeton\Form\Type\ChangePasswordFormType;
use Packeton\Form\Type\CustomerUserType;
use Packeton\Form\Type\ProfileFormType;
use Packeton\Form\Type\SshKeyCredentialType;
use Packeton\Model\DownloadManager;
use Packeton\Model\FavoriteManager;
use Packeton\Model\RedisAdapter;
use Packeton\Security\Provider\AuditSessionProvider;
use Packeton\Security\Token\PatTokenManager;
use Packeton\Service\SubRepositoryHelper;
use Packeton\Util\SshKeyHelper;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UserController extends AbstractController
{
    use ControllerTrait;

    public function __construct(
        protected ManagerRegistry $registry,
        protected FavoriteManager $favoriteManager,
        protected DownloadManager $downloadManager,
        protected UserFormHandler $formHandler,
        protected SubRepositoryHelper $subRepositoryHelper,
        protected AuditSessionProvider $auditSessionProvider,
    ){
    }

    #[Route('/profile', name: 'profile_show')]
    public function showAction(Request $request): Response
    {
        $packages = $this->getUser() instanceof User ? $this->getUserPackages($request, $this->getUser()) : [];

        return $this->render('profile/show.html.twig', [
            'user' => $this->getUser(),
            'meta' => $this->getPackagesMetadata($packages),
            'packages' => $packages,
        ]);
    }

    #[Route('/profile/regenerate-token', name: 'profile_regenerate_token')]

    public function regenerateToken(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('token', $request->query->get('_token'))) {
            return new Response('Invalid Csrf Params', 400);
        }

        $user->generateApiToken();
        $this->getEM()->flush();

        return $this->redirectToRoute('profile_show');
    }

    #[Route('/profile/edit', name: 'profile_edit')]
    public function editAction(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(ProfileFormType::class, $user, ['allow_edit' => !$user->isExternal()]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->getEM()->persist($user);
            $this->getEM()->flush();

            return $this->redirectToRoute('profile_show');
        }

        return $this->render('profile/edit_content.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Change pass
     */
    #[Route('/change-password', name: 'change_password', methods: ['GET', 'POST'])]
    public function changePasswordAction(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(ChangePasswordFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword(
                $this->container->get(UserPasswordHasherInterface::class)->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $this->getEM()->persist($user);
            $this->getEM()->flush();

            return $this->redirectToRoute('profile_show');
        }

        return $this->render('user/change_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/users/', name: 'users_list')]
    public function listAction(Request $request): Response
    {
        $page = $request->query->get('page', 1);

        $qb = $this->registry->getRepository(User::class)
            ->createQueryBuilder('u');

        if ($searchUser = $request->query->get('user_query')) {
            $qb->andWhere('u.username LIKE :searchUser')
                ->setParameter('searchUser', "%{$searchUser}%");
        }

        $paginator = new Pagerfanta(new QueryAdapter($qb, false));
        $paginator->setMaxPerPage(10);

        $paginator->setCurrentPage((int)$page);

        /** @var User[] $paginator */
        return $this->render('user/list.html.twig', [
            'users' => $paginator,
            'searchUser' => $searchUser
        ]);
    }

    #[Route('/users/{name}/update', name: 'users_update')]
    public function updateAction(Request $request, #[Vars(['name' => 'username'])] User $user): Response
    {
        $currentUser = $this->getUser();
        if ($currentUser->getUserIdentifier() !== $user->getUserIdentifier() && !$user->isAdmin()) {
            return $this->handleUpdate($request, $user, 'User has been saved.');
        }

        throw new AccessDeniedHttpException('You can not update yourself or admin user');
    }

    #[Route('/users/create', name: 'users_create')]
    public function createAction(Request $request): Response
    {
        $user = new User();
        $user->generateApiToken();
        $user->setEnabled(true);

        return $this->handleUpdate($request, $user, 'User has been saved.');
    }

    protected function handleUpdate(Request $request, User $user, $flashMessage)
    {
        $form = $this->createForm(CustomerUserType::class, $user, ['is_created' => null === $user->getId()]);

        if ($request->getMethod() === 'POST') {
            if ($this->formHandler->handle($request, $form)) {
                $this->addFlash('success', $flashMessage);
                return new RedirectResponse($this->generateUrl('users_list'));
            }
        }

        return $this->render('user/update.html.twig', [
            'form' => $form->createView(),
            'entity' => $user
        ]);
    }

    #[Route('/profile/tokens', name: 'profile_list_tokens')]
    public function tokenList(PatTokenManager $manager)
    {
        $user = $this->getUser();
        $tokens = $this->registry->getRepository(ApiToken::class)->findAllTokens($user);
        foreach ($tokens as $token) {
            $token->setAttributes($manager->getStats($token->getId()));
        }

        return $this->render('user/token_list.html.twig', ['tokens' => $tokens]);
    }

    #[Route('/users/sessions/list', name: 'users_login_attempts_all')]
    public function userSessionsAction(): Response
    {
        $admins = $this->registry->getRepository(User::class)
            ->createQueryBuilder('u')
            ->resetDQLPart('select')
            ->select('u.usernameCanonical')
            ->andWhere("u.roles LIKE '%ADMIN%'")
            ->getQuery()->getSingleColumnResult();


        $sessions = $this->auditSessionProvider->allSessions($admins);

        return $this->render('user/login_attempts.html.twig', ['sessions' => $sessions]);
    }

    #[Route('/users/{name}/login-attempts', name: 'users_login_attempts')]
    #[Route('/profile/login-attempts', name: 'profile_login_attempts')]
    public function loginAttemptsAction(#[Vars(['name' => 'username'])] User $user = null): Response
    {
        $currentUser = $this->getUser();
        if (null !== $user && true === $user->isAdmin() && $currentUser->getUserIdentifier() !== $user->getUserIdentifier()) {
            throw new AccessDeniedHttpException('You can not see audit login for other admin user');
        }

        $user ??= $currentUser;
        $sessions = $this->auditSessionProvider->getSessions($user->getUserIdentifier());

        return $this->render('user/login_attempts.html.twig', ['sessions' => $sessions, 'username' => $user->getUserIdentifier()]);
    }

    #[Route('/profile/tokens/{id}/delete', name: 'profile_remove_tokens', methods: ['POST'])]
    public function tokenDelete(#[Vars] ApiToken $token)
    {
        $user = $this->getUser();
        $identifier = $token->getOwner() ? $token->getOwner()->getUserIdentifier() : $token->getUserIdentifier();
        if ($identifier === $user->getUserIdentifier()) {
            $this->getEM()->remove($token);
            $this->getEM()->flush();

            $this->addFlash('success', 'Token was removed');
            return new RedirectResponse($this->generateUrl('profile_list_tokens'));
        }

        throw $this->createNotFoundException('Token not found');
    }

    #[Route('/profile/tokens/new', name: 'profile_add_tokens')]
    public function tokenAdd(Request $request)
    {
        $token = new ApiToken();
        $form = $this->createForm(ApiTokenType::class, $token);

        if ($request->getMethod() === 'POST') {
            $em = $this->registry->getManager();
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $user = $this->getUser();

                if ($user instanceof User) {
                    $token->setOwner($user);
                } else {
                    $token->setUserIdentifier($user->getUserIdentifier());
                }
                $token->setApiToken(hash('sha256', random_bytes(32)));

                $em->persist($token);
                $em->flush();
                $this->addFlash('success', 'Token was generated');
                return new RedirectResponse($this->generateUrl('profile_list_tokens'));
            }
        }

        return $this->render('user/token_add.html.twig', [
            'form' => $form->createView(),
            'entity' => $token
        ]);
    }

    #[Route('/users/{name}/packages', name: 'user_packages')]
    public function packagesAction(Request $req, #[Vars(['name' => 'username'])] User $user)
    {
        $packages = $this->getUserPackages($req, $user);

        return $this->render('user/packages.html.twig', [
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
            'user' => $user,
        ]);
    }

    #[Route('/users/sshkey', name: 'user_add_sshkey', methods: ['GET', 'POST'])]
    #[Route('/users/sshkey/{id}', name: 'user_edit_sshkey', methods: ['GET', 'POST'])]
    public function addSSHKeyAction(Request $request, #[Vars] SshCredentials $key = null): Response
    {
        if ($key && !$this->isGranted('VIEW', $key)) {
            throw new AccessDeniedException();
        }

        $sshKey = $key ?: new SshCredentials();
        $form = $this->createForm(SshKeyCredentialType::class, $sshKey);

        if ($request->getMethod() === 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var SshCredentials $sshKey */
                $sshKey = $form->getData();
                $em = $this->registry->getManager();

                if ($sshKey->getKey()) {
                    $fingerprint = SshKeyHelper::getFingerprint($sshKey->getKey());
                    $sshKey->setFingerprint($fingerprint);
                }

                if ($sshKey->getId() === null && $this->getUser() instanceof User) {
                    $sshKey->setOwner($this->getUser());
                }

                $em->persist($sshKey);
                $em->flush();

                $this->addFlash('success', $key ? 'Ssh key updated successfully.' : 'Ssh key added successfully.');
                return new RedirectResponse('/');
            }
        }

        $listKeys = [];
        if ($this->getUser() instanceof User) {
            $listKeys = $this->registry->getRepository(SshCredentials::class)
                ->findBy(['owner' => $this->getUser()]);
        }

        $deleteForm = $this->createFormBuilder()->getForm();

        return $this->render('user/sshkey.html.twig', [
            'form' => $form->createView(),
            'sshKey' => $sshKey,
            'listKeys' => $listKeys,
            'deleteForm' => $deleteForm->createView(),
        ]);
    }

    #[Route('/users/sshkey/{id}/delete', name: 'user_delete_sshkey', methods: ['DELETE', 'POST'])]
    public function deleteSSHKeyAction(Request $request, #[Vars] SshCredentials $key): Response
    {
        if (!$this->isCsrfTokenValid('delete', $request->request->get('_token'))) {
            return new Response('Invalid csrf form', 400);
        }

        if (!$this->isGranted('VIEW', $key)) {
            throw new AccessDeniedException();
        }

        $em = $this->registry->getManager();
        $em->remove($key);
        $em->flush();
        return new RedirectResponse('/');
    }

    #[Route('/users/{name}', name: 'user_profile')]
    public function profileAction(Request $req, #[Vars(['name' => 'username'])] User $user): Response
    {
        $packages = $this->getUserPackages($req, $user);

        return $this->render('user/profile.html.twig', [
            'packages' => $packages,
            'user' => $user,
        ]);
    }

    #[Route('/users/{name}/delete', name: 'user_delete', methods: ['POST'])]
    public function deleteAction(Request $request, #[Vars(['name' => 'username'])] User $user): Response
    {
        if (!$this->isCsrfTokenValid('delete', $request->request->get('_token'))) {
            return new Response('Invalid csrf token', 400);
        }

        $em = $this->registry->getManager();
        $em->remove($user);
        $em->flush();
        $this->addFlash('success', 'User was deleted');

        return new RedirectResponse($this->generateUrl('users_list'));
    }

    #[Route('/users/{name}/favorites', name: 'user_favorites', methods: ['GET'])]
    public function favoritesAction(Request $req, #[Vars(['name' => 'username'])] User $user): Response
    {
        $paginator = new Pagerfanta(
            new RedisAdapter($this->favoriteManager, $user, 'getFavorites', 'getFavoriteCount')
        );

        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage((int)$req->query->get('page', 1));

        return $this->render('user/favorites.html.twig', [
            'packages' => $paginator,
            'user' => $user
        ]);
    }

    #[Route('/users/{name}/favorites', name: 'user_add_fav', defaults: ['_format' => 'json'], methods: ['POST'])]
    public function postFavoriteAction(Request $req, #[Vars(['name' => 'username'])] User $user): Response
    {
        if ($user->getId() !== $this->getUser()->getId()) {
            throw new AccessDeniedException('You can only change your own favorites');
        }

        $package = $req->request->get('package');
        try {
            $package = $this->registry
                ->getRepository(Package::class)
                ->findOneByName($package);
        } catch (NoResultException) {
            throw new NotFoundHttpException('The given package "'.$package.'" was not found.');
        }

        $this->favoriteManager->markFavorite($user, $package);

        return new JsonResponse(['status' => 'success'], 201);
    }

    #[Route(
        '/users/{name}/favorites/{package}',
        name: 'user_remove_fav',
        requirements: ['package' => '%package_name_regex%'],
        defaults: ['_format' => 'json'],
        methods: ['DELETE']
    )]
    public function deleteFavoriteAction(#[Vars(['name' => 'username'])] User $user, #[Vars('name')] Package $package): Response
    {
        if ($user->getId() !== $this->getUser()->getId()) {
            throw new AccessDeniedException('You can only change your own favorites');
        }

        $this->favoriteManager->removeFavorite($user, $package);

        return new JsonResponse(['status' => 'success'], 204);
    }

    /**
     * @param Request $req
     * @param User|UserInterface $user
     * @return Pagerfanta
     */
    protected function getUserPackages($req, $user)
    {
        $packages = $this->registry
            ->getRepository(Package::class)
            ->getFilteredQueryBuilder(['maintainer' => $user->getId()], true);
        $packages = $this->subRepositoryHelper->applySubRepository($packages);

        $paginator = new Pagerfanta(new QueryAdapter($packages, true));
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage((int)$req->query->get('page', 1));

        return $paginator;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedServices(): array
    {
        return array_merge(
            parent::getSubscribedServices(),
            [
                UserPasswordHasherInterface::class
            ]
        );
    }
}
