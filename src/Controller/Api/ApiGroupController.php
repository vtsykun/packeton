<?php

declare(strict_types=1);

namespace Packeton\Controller\Api;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Entity\Group;
use Packeton\Form\Handler\DefaultFormHandler;
use Packeton\Form\Type\GroupApiType;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api', name: 'api_', defaults: ['_format' => 'json'])]
class ApiGroupController extends AbstractController
{
    use ApiControllerTrait;

    public function __construct(
        protected ManagerRegistry $registry,
    ){
    }

    #[Route('/groups', name: 'groups_lists', methods: ['GET'])]
    public function lists(Request $request): Response
    {
        $repo = $this->registry->getRepository(Group::class);
        $page = (int)$request->query->get('page', 1);

        $qb = $repo
            ->createQueryBuilder('g')
            ->orderBy('g.id', 'ASC');

        $paginator = new Pagerfanta(new QueryAdapter($qb, false));
        $paginator->setMaxPerPage(25);
        $paginator->setCurrentPage($page);

        return $this->json(array_map($repo->getApiData(...), $paginator->jsonSerialize()));
    }

    #[Route('/groups', name: 'groups_create', methods: ['POST'])]
    public function create(Request $request, DefaultFormHandler $handler): Response
    {
        $entity = new Group();
        $form = $this->createForm(GroupApiType::class, $entity);

        $data = $this->getJsonPayload($request);
        if ($handler->handle($data, $form)) {
            return new JsonResponse(['id' => $entity->getId()], 201);
        }

        return $this->badRequest($form);
    }

    #[Route('/group/{id}', name: 'groups_item', methods: ['GET'])]
    public function item(#[Vars] Group $entity): Response
    {
        $repo = $this->registry->getRepository(Group::class);
        return $this->json($repo->getApiData($entity));
    }

    #[Route('/group/{id}', name: 'groups_update', methods: ['PUT'])]
    public function update(Request $request, #[Vars] Group $entity, DefaultFormHandler $handler): Response
    {
        $form = $this->createForm(GroupApiType::class, $entity);
        $data = $this->getJsonPayload($request);
        if ($handler->handle($data, $form, true)) {
            return new JsonResponse([], 204);
        }

        return $this->badRequest($form);
    }

    #[Route('/group/{id}', name: 'groups_delete', methods: ['DELETE'])]
    public function delete(#[Vars] Group $entity): Response
    {
        $this->getEM()->remove($entity);
        $this->getEM()->flush();

        return new JsonResponse([], 204);
    }
}
