<?php

namespace Packagist\WebBundle\Controller;

use Packagist\WebBundle\Entity\Webhook;
use Packagist\WebBundle\Form\Type\WebhookType;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @Route("/webhooks")
 */
class WebhookController extends Controller
{
    /**
     * @Template("PackagistWebBundle:Webhook:index.html.twig")
     * @Route("", name="webhook_index")
     *
     * {@inheritdoc}
     */
    public function indexAction(Request $request)
    {
        $page = $request->query->get('page', 1);
        $qb = $this->getDoctrine()
            ->getRepository(Webhook::class)
            ->createQueryBuilder('w')
            ->orderBy('w.id');

        $qb->where('w.owner IS NULL')
            ->orWhere("w.visibility = 'global'")
            ->orWhere("w.visibility = 'user' AND IDENTITY(w.owner) = :owner")
            ->setParameter('owner', $this->getUser()->getId());

        $paginator = new Pagerfanta(new DoctrineORMAdapter($qb, false));
        $paginator->setMaxPerPage(10);
        $paginator->setCurrentPage($page, false, true);

        /** @var Webhook[] $webhooks */
        $webhooks = $qb->getQuery()->getResult();
        return [
            'webhooks' => $webhooks,
        ];
    }

    /**
     * @Template("PackagistWebBundle:Webhook:update.html.twig")
     * @Route("/create", name="webhook_create")
     *
     * {@inheritdoc}
     */
    public function createAction(Request $request)
    {
        $hook = new Webhook();
        return $this->handleUpdate($request, $hook, 'Successfully saved.');
    }

    /**
     * @Template("PackagistWebBundle:Webhook:update.html.twig")
     * @Route("/update/{id}", name="webhook_update", requirements={"id"="\d+"})
     *
     * {@inheritdoc}
     */
    public function updateAction(Request $request, Webhook $hook)
    {
        if ($hook->getVisibility() === Webhook::USER_VISIBLE
            && $hook->getOwner()
            && $hook->getOwner()->getId() !== $this->getUser()->getId()
        ) {
            throw new AccessDeniedException();
        }

        return $this->handleUpdate($request, $hook, 'Successfully saved.');
    }

    /**
     * @param Request $request
     * @param Webhook $entity
     * @param $flashMessage
     *
     * @return array|RedirectResponse
     */
    protected function handleUpdate(Request $request, Webhook $entity, string $flashMessage)
    {
        $em = $this->getDoctrine()->getManager();
        $form = $this->createForm(WebhookType::class, $entity);
        if ($request->getMethod() === 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $entity = $form->getData();
                $em->persist($entity);
                $em->flush();
                $this->addFlash('success', $flashMessage);
                return new RedirectResponse($this->generateUrl('webhook_index'));
            }
        }

        return [
            'form' => $form->createView(),
            'entity' => $entity
        ];
    }
}
