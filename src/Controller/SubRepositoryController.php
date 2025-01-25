<?php

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Entity\SubRepository;
use Packeton\Form\Handler\SubrepositoryFormHandler;
use Packeton\Form\Type\SubRepositoryType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/subrepository')]
class SubRepositoryController extends AbstractController
{
    public function __construct(
        protected ManagerRegistry $registry,
        protected SubrepositoryFormHandler $formHandler,
    ) {
    }

    #[Route('', name: 'subrepository_index')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(): Response
    {
        $repos = $this->registry->getRepository(SubRepository::class)->findAll();
        return $this->render('subrepository/index.html.twig', ['repos' => $repos]);
    }

    #[Route('/create', name: 'subrepository_create')]
    #[IsGranted('ROLE_ADMIN')]
    public function createAction(Request $request): Response
    {
        $entity = new SubRepository();
        return $this->handleUpdate($request, $entity, 'Saved successfully');
    }

    #[Route('/{id}/update', name: 'subrepository_update')]
    #[IsGranted('ROLE_ADMIN')]
    public function updateAction(Request $request, #[Vars] SubRepository $entity): Response
    {
        return $this->handleUpdate($request, $entity, 'Saved successfully');
    }

    #[Route('/{id}/delete', name: 'subrepository_delete')]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteAction(Request $request, #[Vars] SubRepository $entity): Response
    {
        if (!$this->isCsrfTokenValid('delete', $request->request->get('_token'))) {
            $this->addFlash('error', 'Csrf token is not valid');
        } else {
            $em = $this->registry->getManager();
            $em->remove($entity);
            $em->flush();
            $this->formHandler->deleteCache();
            $this->addFlash('success', 'Subrepository ' . $entity->getName() . ' has been deleted successfully');
        }

        return $this->redirect($this->generateUrl("subrepository_index"));
    }

    #[Route('/{id}/switch', name: 'subrepository_switch')]
    public function switchAction(Request $request, #[Vars] SubRepository $entity): Response
    {
        $request->getSession()->set('_sub_repo', $entity->getId());

        return $this->redirectToRoute('home');
    }

    #[Route('/switch-root', name: 'subrepository_switch_root')]
    public function switchRoot(Request $request): Response
    {
        $request->getSession()->remove('_sub_repo');

        return $this->redirectToRoute('home');
    }

    protected function handleUpdate(Request $request, SubRepository $group, string $flashMessage): Response
    {
        $form = $this->createForm(SubRepositoryType::class, $group);
        if ($this->formHandler->handle($request, $form)) {
            $this->addFlash('success', $flashMessage);
            return new RedirectResponse($this->generateUrl('subrepository_index'));
        }

        return $this->render('subrepository/update.html.twig', [
            'form' => $form->createView(),
            'entity' => $group
        ]);
    }
}
