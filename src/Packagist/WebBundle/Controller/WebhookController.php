<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Controller;

use Packagist\WebBundle\Entity\Job;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Webhook;
use Packagist\WebBundle\Form\Type\WebhookType;
use Packagist\WebBundle\Webhook\HookResponse;
use Packagist\WebBundle\Webhook\HookTestAction;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Response;
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
        $qb = $this->getDoctrine()
            ->getRepository(Webhook::class)
            ->createQueryBuilder('w')
            ->orderBy('w.id');

        $qb->where('w.owner IS NULL')
            ->orWhere("w.visibility = 'global'")
            ->orWhere("w.visibility = 'user' AND IDENTITY(w.owner) = :owner")
            ->setParameter('owner', $this->getUser()->getId());

        /** @var Webhook[] $webhooks */
        $webhooks = $qb->getQuery()->getResult();
        $deleteCsrfToken = $this->get('security.csrf.token_manager')->getToken('webhook_delete');

        return [
            'webhooks' => $webhooks,
            'deleteCsrfToken' => $deleteCsrfToken,
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
        if (!$this->isGranted('VIEW', $hook)) {
            throw new AccessDeniedException();
        }

        $response = $this->handleUpdate($request, $hook, 'Successfully saved.');
        if ($request->getMethod() === 'GET') {
            $response['jobs'] = $this->getDoctrine()
                ->getRepository(Job::class)
                ->findJobsByType('webhook:send', $hook->getId());
        }

        return $response;
    }

    /**
     * @Route("/delete/{id}", name="webhook_delete")
     * {@inheritdoc}
     */
    public function deleteAction(Request $request, Webhook $hook)
    {
        if (!$this->isGranted('VIEW', $hook)) {
            throw new AccessDeniedException;
        }
        if (!$this->isCsrfTokenValid('webhook_delete', $request->request->get('_token'))) {
            throw new AccessDeniedException;
        }

        $em = $this->getDoctrine()->getManager();
        $em->remove($hook);
        $em->flush();
        return new Response('', 204);
    }

    /**
     * @Route("/job/{id}", name="webhook_job_action")
     *
     * {@inheritdoc}
     */
    public function jobAction(Job $entity)
    {
        $hook = $this->getDoctrine()->getRepository(Webhook::class)
            ->find($entity->getPackageId());
        if ($hook === null || !$this->isGranted('VIEW', $hook)) {
            throw new AccessDeniedException();
        }

        $result = $entity->getResult() ?: [];
        try {
            $response = array_map(HookResponse::class.'::fromArray', $result['response'] ?? []);
        } catch (\Throwable $e) {
            $response = null;
        }

        return $this->render('@PackagistWeb/Webhook/hook_widget.html.twig', [
            'response' => $response,
            'errors' => $result['exceptionMsg'] ?? null
        ]);
    }

    /**
     * @Template("PackagistWebBundle:Webhook:test.html.twig")
     * @Route("/test/{id}/send", name="webhook_test_action", requirements={"id"="\d+"})
     *
     * {@inheritdoc}
     */
    public function testAction(Request $request, Webhook $entity)
    {
        if (!$this->isGranted('VIEW', $entity)) {
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
            ->add('versions', TextType::class, ['required' => false])
            ->add('payload', TextareaType::class, ['required' => false])
            ->add('sendReal', CheckboxType::class, [
                'required' => false,
                'label' => 'Send real request?'
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

            return $this->render('@PackagistWeb/Webhook/hook_widget.html.twig', [
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
