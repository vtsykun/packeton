<?php

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
use Packeton\Entity\Package;
use Packeton\Entity\SshCredentials;
use Packeton\Entity\User;
use Packeton\Form\Type\ChangePasswordFormType;
use Packeton\Form\Type\CustomerUserType;
use Packeton\Form\Type\ProfileFormType;
use Packeton\Form\Type\SshKeyCredentialType;
use Packeton\Model\DownloadManager;
use Packeton\Model\FavoriteManager;
use Packeton\Model\RedisAdapter;
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
    ){}

    /**
     * Show the user.
     * @Route("/profile", name="profile_show")
     */
    public function showAction(Request $request)
    {
        $packages = $this->getUser() instanceof User ? $this->getUserPackages($request, $this->getUser()) : [];

        return $this->render('profile/show.html.twig', [
            'user' => $this->getUser(),
            'meta' => $this->getPackagesMetadata($packages),
            'packages' => $packages,
        ]);
    }

    /**
     * Show the user.
     * @Route("/profile/edit", name="profile_edit")
     */
    public function editAction(Request $request)
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(ProfileFormType::class, $user);

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
     * @Route("/change-password", name="change_password", methods={"GET", "POST"})
     */
    public function changePasswordAction(Request $request)
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

    /**
     * @Route("/users/", name="users_list")
     */
    public function listAction(Request $request)
    {
        $page = $request->query->get('page', 1);

        $qb = $this->registry->getRepository(User::class)
            ->createQueryBuilder('u');
        $qb->where("u.roles NOT LIKE '%ADMIN%'")
            ->orderBy('u.id', 'DESC');

        $paginator = new Pagerfanta(new QueryAdapter($qb, false));
        $paginator->setMaxPerPage(6);

        $csrfForm = $this->createFormBuilder([])->getForm();
        $paginator->setCurrentPage((int)$page);

        /** @var User[] $paginator */
        return $this->render('user/list.html.twig', [
            'users' => $paginator,
            'csrfForm' => $csrfForm
        ]);
    }

    /**
     * @Route("/users/{name}/update", name="users_update")
     */
    public function updateAction(Request $request, #[Vars(['name' => 'username'])] User $user)
    {
        $currentUser = $this->getUser();
        if ($currentUser->getUserIdentifier() !== $user->getUserIdentifier() && !$user->isAdmin()) {
            return  $this->handleUpdate($request, $user, 'User has been saved.');
        }

        throw new AccessDeniedHttpException('You can not update yourself or admin user');
    }

    /**
     * @Route("/users/create", name="users_create")
     *
     * @param Request $request
     * @return mixed
     */
    public function createAction(Request $request)
    {
        $user = new User();
        $user->generateApiToken();

        return $this->handleUpdate($request, $user, 'User has been saved.');
    }

    protected function handleUpdate(Request $request, User $user, $flashMessage)
    {
        $form = $this->createForm(CustomerUserType::class, $user);
        if ($request->getMethod() === 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var User $user */
                $user = $form->getData();
                if ($planPassword = $user->getPlainPassword()) {
                    $user->setPassword(
                        $this->container->get(UserPasswordHasherInterface::class)->hashPassword($user, $planPassword)
                    );
                }

                $form->get('fullAccess')->getData() ? $user->addRole('ROLE_FULL_CUSTOMER') :
                    $user->removeRole('ROLE_FULL_CUSTOMER');

                $this->getEM()->persist($user);
                $this->getEM()->flush();

                $this->addFlash('success', $flashMessage);
                return new RedirectResponse($this->generateUrl('users_list'));
            }
        }

        return $this->render('user/update.html.twig', [
            'form' => $form->createView(),
            'entity' => $user
        ]);
    }

    /**
     * @Route("/users/{name}/packages", name="user_packages")
     */
    public function packagesAction(Request $req, #[Vars(['name' => 'username'])] User $user)
    {
        $packages = $this->getUserPackages($req, $user);

        return $this->render('user/packages.html.twig', [
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
            'user' => $user,
        ]);
    }

    /**
     *  @Route("/users/sshkey", name="user_add_sshkey", methods={"GET", "POST"})
     *  @Route("/users/sshkey/{id}", name="user_edit_sshkey", methods={"GET", "POST"})
     * {@inheritdoc}
     */
    public function addSSHKeyAction(Request $request, #[Vars] SshCredentials $key = null)
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

        return $this->render('user/sshkey.html.twig', [
            'form' => $form->createView(),
            'sshKey' => $sshKey,
            'listKeys' => $listKeys,
        ]);
    }

    /**
     * @Route("/users/{name}", name="user_profile")
     */
    public function profileAction(Request $req, #[Vars(['name' => 'username'])] User $user)
    {
        $deleteForm = $this->createFormBuilder([])->getForm();
        $packages = $this->getUserPackages($req, $user);

        return $this->render('user/profile.html.twig', [
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
            'user' => $user,
            'deleteForm' => $deleteForm->createView()
        ]);
    }

    /**
     * @Route("/users/{name}/delete", name="user_delete")
     */
    public function deleteAction(Request $request, #[Vars(['name' => 'username'])] User $user)
    {
        $form = $this->createFormBuilder([])->getForm();
        $form->submit($request->request->get('form'));
        if ($form->isValid()) {
            $request->getSession()->save();
            $em = $this->registry->getManager();
            $em->remove($user);
            $em->flush();

            return new RedirectResponse('/');
        }

        return new Response('Invalid form input', 400);
    }

    /**
     * @Route("/users/{name}/favorites", name="user_favorites", methods={"GET"})
     */
    public function favoritesAction(Request $req, #[Vars(['name' => 'username'])] User $user)
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

    /**
     * @Route("/users/{name}/favorites", name="user_add_fav", defaults={"_format" = "json"}, methods={"POST"})
     */
    public function postFavoriteAction(Request $req, #[Vars(['name' => 'username'])] User $user)
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

    /**
     * @Route(
     *     "/users/{name}/favorites/{package}",
     *     name="user_remove_fav",
     *     defaults={"_format" = "json"},
     *     requirements={"package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"},
     *     methods={"DELETE"}
     * )
     */
    public function deleteFavoriteAction(#[Vars(['name' => 'username'])] User $user, #[Vars('name')] Package $package)
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
