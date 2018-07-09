<?php

namespace Packagist\WebBundle\Controller;

use Packagist\WebBundle\Entity\Group;
use Packagist\WebBundle\Form\Type\GroupType;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class GroupController extends Controller
{
    /**
     * @Template()
     * @Route("/groups/", name="groups_index")
     *
     * @param Request $request
     * @return mixed
     */
    public function indexAction(Request $request)
    {
        $page = $request->query->get('page', 1);
        $qb = $this->getDoctrine()->getRepository('PackagistWebBundle:Group')
            ->createQueryBuilder('g');
        $qb->orderBy('g.id', 'DESC');

        $paginator = new Pagerfanta(new DoctrineORMAdapter($qb, false));
        $paginator->setMaxPerPage(6);
        $csrfForm = $this->createFormBuilder([])->getForm();

        $paginator->setCurrentPage($page, false, true);
        return [
            'groups' => $paginator,
            'csrfForm' => $csrfForm
        ];
    }

    /**
     * @Template("PackagistWebBundle:Group:update.html.twig")
     * @Route("/groups/create", name="groups_create")
     *
     * @param Request $request
     * @return mixed
     */
    public function createAction(Request $request)
    {
        $group = new Group();
        return $this->handleUpdate($request, $group, 'Group has been saved successfully');
    }

    /**
     * @Template()
     * @Route("/groups/{id}/update", name="groups_update")
     *
     * @param Group $group
     * @param Request $request
     * @return mixed
     */
    public function updateAction(Request $request, Group $group)
    {
        return $this->handleUpdate($request, $group, 'Group has been saved successfully');
    }

    /**
     * @Route("/groups/{id}/delete", name="groups_delete")
     *
     * @param Group $group
     * @param Request $request
     * @return mixed
     */
    public function deleteAction(Request $request, Group $group)
    {
        $form = $this->createFormBuilder([])->getForm();
        $form->submit($request->get('form'));
        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
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
                $em = $this->getDoctrine()->getManager();
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
