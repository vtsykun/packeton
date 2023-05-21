<?php

declare(strict_types=1);

namespace Packeton\Controller\Api;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Entity\User;
use Packeton\Form\Handler\UserFormHandler;
use Packeton\Form\Type\CustomerUserType;
use Packeton\Repository\UserRepository;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api', name: 'api_', defaults: ['_format' => 'json'])]
class ApiUserController extends AbstractController
{
    use ApiControllerTrait;

    public function __construct(
        protected ManagerRegistry $registry,
    ) {
    }

    #[Route('/users', name: 'users_lists', methods: ['GET'])]
    public function lists(Request $request): Response
    {
        /** @var UserRepository $repo */
        $repo = $this->registry->getRepository(User::class);
        $page = (int)$request->query->get('page', 1);

        $qb = $this->registry->getRepository(User::class)
            ->createQueryBuilder('u');
        $qb->where("u.roles NOT LIKE '%ADMIN%'")
            ->orderBy('u.id', 'ASC');

        $paginator = new Pagerfanta(new QueryAdapter($qb, false));
        $paginator->setMaxPerPage(25);
        $paginator->setCurrentPage($page);

        return $this->json(array_map($repo->getApiData(...), $paginator->jsonSerialize()));
    }

    #[Route('/user/{id}', name: 'users_get', methods: ['GET'])]
    public function item(#[Vars] User $user): Response
    {
        $currentUser = $this->getUser();
        if ($currentUser->getUserIdentifier() === $user->getUserIdentifier() || $user->isAdmin()) {
            throw new AccessDeniedHttpException('You can not see yourself or admin user');
        }

        /** @var UserRepository $repo */
        $repo = $this->registry->getRepository(User::class);

        return $this->json($repo->getApiData($user));
    }

    #[Route('/users', name: 'users_create', methods: ['POST'])]
    public function create(Request $request, UserFormHandler $handler): Response
    {
        $user = new User();
        $form = $this->createForm(CustomerUserType::class, $user, ['csrf_protection' => false, 'is_created' => true]);

        $data = $this->getJsonPayload($request);
        if ($handler->handle($data, $form)) {
            return new JsonResponse(['id' => $user->getId()], 201);
        }

        return $this->badRequest($form);
    }

    #[Route('/user/{id}', name: 'users_update', methods: ['PUT'])]
    public function update(Request $request, #[Vars] User $user, UserFormHandler $handler): Response
    {
        $currentUser = $this->getUser();
        if ($currentUser->getUserIdentifier() === $user->getUserIdentifier() || $user->isAdmin()) {
            throw new AccessDeniedHttpException('You can not update yourself or admin user');
        }

        $form = $this->createForm(CustomerUserType::class, $user, ['csrf_protection' => false,]);

        $data = $this->getJsonPayload($request);
        if ($handler->handle($data, $form, true)) {
            return new JsonResponse([], 204);
        }

        return $this->badRequest($form);
    }

    #[Route('/user/{id}', name: 'users_delete', methods: ['DELETE'])]
    public function delete(#[Vars] User $user): Response
    {
        $currentUser = $this->getUser();
        if ($currentUser->getUserIdentifier() === $user->getUserIdentifier() || $user->isAdmin()) {
            throw new AccessDeniedHttpException('You can not update yourself or admin user');
        }

        $this->getEM()->remove($user);
        $this->getEM()->flush();

        return new JsonResponse([], 204);
    }
}
