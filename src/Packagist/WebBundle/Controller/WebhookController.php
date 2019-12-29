<?php

namespace Packagist\WebBundle\Controller;

use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Webhook;
use Packagist\WebBundle\Form\Type\HookTestActionType;
use Packagist\WebBundle\Form\Type\WebhookType;
use Packagist\WebBundle\Webhook\HookTestAction;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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
        if ($this->isGranted('VIEW', $hook)) {
            throw new AccessDeniedException();
        }

        return $this->handleUpdate($request, $hook, 'Successfully saved.');
    }

    /**
     * @Template("PackagistWebBundle:Webhook:test.html.twig")
     * @Route("/test/{id}/send", name="webhook_test_action", requirements={"id"="\d+"})
     *
     * {@inheritdoc}
     */
    public function testAction(Request $request, Webhook $entity)
    {
        if ($this->isGranted('VIEW', $entity)) {
            throw new AccessDeniedException();
        }

        $testAction = $this->get(HookTestAction::class);

        $form = $this->createFormBuilder()
            ->add('event', ChoiceType::class, [
                'required' => true,
                'choices' => WebhookType::getEventsChoices(),
            ])
            ->add('package', EntityType::class, [
                'class' => Package::class,
                'choice_label' => 'name',
                'required' => false,
            ])
            ->add('versions', TextType::class, [
                'required' => false,
            ])
            ->add('sendReal', CheckboxType::class, [
                'required' => false,
                'label' => 'Send request?'
            ])
            ->getForm();

        $errors = $response = null;
        if ($request->getMethod() === 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                $data['user'] = $this->getUser();
                try {
                    $response = $testAction->runTest($entity, $data);
                } catch (\Throwable $exception) {
                    $errors = $exception->getMessage();
                }
            } else {
                $errors = $form->getErrors(true);
            }

            return $this->render('@PackagistWeb/Webhook/test_widget.html.twig', [
                'response' => $response,
                'errors' => $errors
            ]);
        }

        return [
            'form' => $form->createView(),
            'entity' => $entity,
        ];
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
