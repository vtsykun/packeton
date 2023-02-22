<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Entity\Group;
use Packeton\Form\Type\GroupType;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class GroupController extends AbstractController
{
    public function __construct(
        protected ManagerRegistry $registry
    ){
    }

    #[Route('/groups', name: 'groups_index')]
    public function indexAction(Request $request): Response
    {
        $page = $request->query->get('page', 1);
        $qb = $this->registry->getRepository(Group::class)
            ->createQueryBuilder('g');

        if ($searchGroup = $request->query->get('search')['group'] ?? null) {
            $searchGroup = \mb_strtolower($searchGroup);
            $qb->andWhere('LOWER(g.name) LIKE :search')
                ->setParameter('search', "%{$searchGroup}%");
        }

        $qb->orderBy('g.id', 'DESC');

        $paginator = new Pagerfanta(new QueryAdapter($qb, false));
        $paginator->setMaxPerPage(10);
        $csrfForm = $this->createFormBuilder([])->getForm();

        $paginator->setCurrentPage((int)$page);

        return $this->render('group/index.html.twig', [
            'groups' => $paginator,
            'csrfForm' => $csrfForm,
            'searchGroup' => $searchGroup,
        ]);
    }

    #[Route('/groups/create', name: 'groups_create')]
    public function createAction(Request $request): Response
    {
        $group = new Group();
        $data = $this->handleUpdate($request, $group, 'Group has been saved successfully');

        return $data instanceof Response ? $data : $this->render('group/update.html.twig', $data);
    }

    #[Route('/groups/{id}/update', name: 'groups_update')]
    public function updateAction(Request $request, #[Vars] Group $group): Response
    {
        $data = $this->handleUpdate($request, $group, 'Group has been saved successfully');

        return $data instanceof Response ? $data : $this->render('group/update.html.twig', $data);
    }

    #[Route('/groups/{id}/delete', name: 'groups_delete')]
    public function deleteAction(Request $request, #[Vars] Group $group): Response
    {
        $form = $this->createFormBuilder()->getForm();
        $form->submit($request->get('form'));
        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->registry->getManager();
            $em->remove($group);
            $em->flush();
            $this->addFlash('success', 'Group ' . $group->getName() . ' has been deleted successfully');
        } else {
            $this->addFlash('error', (string) $form->getErrors(true));
        }

        return $this->redirect(
            $this->generateUrl("groups_index")
        );
    }

    protected function handleUpdate(Request $request, Group $group, $flashMessage)
    {
        $form = $this->createForm(GroupType::class, $group);
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
