<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Entity\WebhookSecret;
use Packeton\Form\Type\WebhookSecretType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/webhook-secrets')]
#[IsGranted('ROLE_ADMIN')]
class WebhookSecretController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $registry,
    ) {
    }

    #[Route('', name: 'webhook_secret_index', methods: ['GET'])]
    public function indexAction(): Response
    {
        return $this->render('webhook_secret/index.html.twig', [
            'secrets' => $this->registry->getRepository(WebhookSecret::class)->findAllOrdered(),
        ]);
    }

    #[Route('/create', name: 'webhook_secret_create', methods: ['GET', 'POST'])]
    public function createAction(Request $request): Response
    {
        $secret = (new WebhookSecret())->setSecret(WebhookSecret::generateSecret());
        $form = $this->createForm(WebhookSecretType::class, $secret);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->registry->getManager();
            $entityManager->persist($secret);
            $entityManager->flush();

            $response = $this->render('webhook_secret/show_secret.html.twig', [
                'secret' => $secret,
                'generatedSecret' => $secret->getSecret(),
            ]);
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');

            return $response;
        }

        return $this->render('webhook_secret/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/delete', name: 'webhook_secret_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteAction(Request $request, #[Vars] WebhookSecret $secret): Response
    {
        if (!$this->isCsrfTokenValid('webhook_secret_delete_'.$secret->getId(), $request->request->get('_token'))) {
            return new Response('Invalid csrf token', Response::HTTP_BAD_REQUEST);
        }

        $entityManager = $this->registry->getManager();
        $entityManager->remove($secret);
        $entityManager->flush();

        $this->addFlash('success', 'Webhook secret deleted.');

        return $this->redirectToRoute('webhook_secret_index');
    }
}
