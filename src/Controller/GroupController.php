<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Entity\Group;
use Packeton\Form\Type\GroupType;
use Packeton\Security\Acl\GroupOwnerVoter;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class GroupController extends AbstractController
{
    public function __construct(
        protected ManagerRegistry $registry,
        protected ParameterBagInterface $parameterBag,
    ){
    }

    #[Route('/groups', name: 'groups_index')]
    public function indexAction(Request $request): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            if (!$this->isGranted('ROLE_MAINTAINER') || !$this->parameterBag->get('packeton.allow_maintainer_group_creation')) {
                throw $this->createAccessDeniedException();
            }
        }

        $page = $request->query->get('page', 1);
        $qb = $this->registry->getRepository(Group::class)
            ->createQueryBuilder('g');

        if (!$this->isGranted('ROLE_ADMIN') && $user = $this->getUser()) {
            $qb->innerJoin('g.owners', 'o')
                ->andWhere('o = :user')
                ->setParameter('user', $user);
        }

        if ($searchGroup = $request->query->get('search_group')) {
            $searchGroup = \mb_strtolower($searchGroup);
            $qb->andWhere('LOWER(g.name) LIKE :search')
                ->setParameter('search', "%{$searchGroup}%");
        }

        $qb->orderBy('g.id', 'DESC');

        $paginator = new Pagerfanta(new QueryAdapter($qb, false));
        $paginator->setMaxPerPage(10);

        $paginator->setCurrentPage((int)$page);

        return $this->render('group/index.html.twig', [
            'groups' => $paginator,
            'searchGroup' => $searchGroup,
            'allowMaintainerGroupCreation' => $this->parameterBag->get('packeton.allow_maintainer_group_creation'),
        ]);
    }

    #[Route('/groups/create', name: 'groups_create')]
    public function createAction(Request $request): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            // admin can always create
        } elseif (
            $this->parameterBag->get('packeton.allow_maintainer_group_creation')
            && $this->isGranted('ROLE_MAINTAINER')
        ) {
            // maintainer can create when config enabled
        } else {
            throw $this->createAccessDeniedException();
        }

        $group = new Group();

        if (!$this->isGranted('ROLE_ADMIN') && $user = $this->getUser()) {
            $group->addOwner($user);
        }

        $data = $this->handleUpdate($request, $group, 'Group has been saved successfully', isAdmin: $this->isGranted('ROLE_ADMIN'));

        return $data instanceof Response ? $data : $this->render('group/update.html.twig', $data);
    }

    #[Route('/groups/{id}/update', name: 'groups_update')]
    public function updateAction(Request $request, #[Vars] Group $group): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->parameterBag->get('packeton.allow_maintainer_group_creation')) {
            throw $this->createAccessDeniedException();
        }

        $this->denyAccessUnlessGranted(GroupOwnerVoter::EDIT, $group);

        $data = $this->handleUpdate($request, $group, 'Group has been saved successfully', isAdmin: $this->isGranted('ROLE_ADMIN'));

        return $data instanceof Response ? $data : $this->render('group/update.html.twig', $data);
    }

    #[Route('/groups/{id}/delete', name: 'groups_delete')]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteAction(Request $request, #[Vars] Group $group): Response
    {
        if (!$this->isCsrfTokenValid('delete', $request->request->get('_token'))) {
            $this->addFlash('error', 'Csrf token is not valid');
        } else {
            $em = $this->registry->getManager();
            $em->remove($group);
            $em->flush();
            $this->addFlash('success', 'Group ' . $group->getName() . ' has been deleted successfully');
        }

        return $this->redirect($this->generateUrl("groups_index"));
    }

    protected function handleUpdate(Request $request, Group $group, $flashMessage, bool $isAdmin = true)
    {
        $form = $this->createForm(GroupType::class, $group, ['is_admin' => $isAdmin]);
        if ($request->getMethod() === 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $group = $form->getData();
                $em = $this->registry->getManager();
                $em->persist($group);
                $em->flush();

                $this->addFlash('success', $flashMessage);
                return new RedirectResponse($this->generateUrl('groups_index'));
            }
        }

        return [
            'form' => $form->createView(),
            'entity' => $group
        ];
    }
}
