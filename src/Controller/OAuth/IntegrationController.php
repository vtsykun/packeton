<?php

declare(strict_types=1);

namespace Packeton\Controller\OAuth;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Composer\JsonResponse;
use Packeton\Entity\OAuthIntegration;
use Packeton\Entity\Package;
use Packeton\Exception\SkipLoggerExceptionInterface;
use Packeton\Form\Type\IntegrationSettingsType;
use Packeton\Integrations\Exception\NotFoundAppException;
use Packeton\Integrations\IntegrationRegistry;
use Packeton\Integrations\AppInterface;
use Packeton\Integrations\Model\AppUtils;
use Packeton\Integrations\Model\FormSettingsInterface;
use Packeton\Integrations\Model\OAuth2State;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/integration')]
class IntegrationController extends AbstractController
{
    public function __construct(
        protected IntegrationRegistry $integrations,
        protected ManagerRegistry $registry,
        protected LoggerInterface $logger
    ) {
    }

    #[Route('', name: 'integration_list')]
    public function listAction(): Response
    {
        $integrations = $this->integrations->findAllApps();
        return $this->render('integration/list.html.twig', ['integrations' => $integrations]);
    }

    #[Route('/connect', name: 'integration_connect')]
    public function connect(): Response
    {
        $integrations = $this->integrations->findAllApps();
        return $this->render('integration/connect.html.twig', ['integrations' => $integrations]);
    }

    #[Route('/{alias}/{id}', name: 'integration_index')]
    public function index(string $alias, #[Vars] OAuthIntegration $oauth): Response
    {
        $client = $this->getClient($alias, $oauth);
        $orgs = [];
        $errorMsg = null;
        try {
            $orgs = $client->organizations($oauth);
        } catch (\Throwable $e) {
            $e instanceof SkipLoggerExceptionInterface ? $this->logger->info($e->getMessage(), ['e' => $e]) : $this->logger->error($e->getMessage(), ['e' => $e]);
            $errorMsg = $e->getMessage();
        }

        $config = $client->getConfig($oauth);

        return $this->render('integration/index.html.twig', [
            'canEdit' => $this->canEdit($oauth),
            'canDelete' => $this->canDelete($oauth),
            'orgs' => $orgs,
            'client' => $client,
            'alias' => $alias,
            'config' => $config,
            'oauth' => $oauth,
            'errorMsg' => $errorMsg,
        ]);
    }

    #[Route('/all/{id}/repos', name: 'integration_repos', requirements: ['id' => '\d+'], format: "json")]
    public function repos(#[Vars] OAuthIntegration $oauth): Response
    {
        $client = $this->getClient($oauth->getAlias(), $oauth);
        $repos = $client->repositories($oauth);

        return new JsonResponse($oauth->filteredRepos($repos, true));
    }

    #[Route('/{alias}/{id}/settings', name: 'integration_settings')]
    public function settings(Request $request, string $alias, #[Vars] OAuthIntegration $oauth): Response
    {
        $client = $this->getClient($alias, $oauth);
        $repos = [];
        $errorMsg = null;
        try {
            $repos = $client->repositories($oauth);
        } catch (\Throwable $e) {
            $e instanceof SkipLoggerExceptionInterface ? $this->logger->info($e->getMessage(), ['e' => $e]) : $this->logger->error($e->getMessage(), ['e' => $e]);
            $errorMsg = $e->getMessage();
        }

        [$formType, $formData] = $client instanceof FormSettingsInterface ? $client->getFormSettings($oauth) : [IntegrationSettingsType::class, []];

        $form = $this->createForm($formType, $oauth, $formData + ['repos' => $repos]);
        $form->handleRequest($request);
        $config = $client->getConfig($oauth);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->registry->getManager()->flush();
            $this->addFlash('success', 'The integration settings has been updated.');
            return $this->redirect($this->generateUrl('integration_index', ['alias' => $alias, 'id' => $oauth->getId()]));
        }

        return $this->render('integration/settings.html.twig', [
            'form' => $form->createView(),
            'client' => $client,
            'alias' => $alias,
            'config' => $config,
            'oauth' => $oauth,
            'errorMsg' => $errorMsg,
        ]);
    }

    #[Route('/{alias}/{id}/connect', name: 'integration_connect_org')]
    public function connectOrg(Request $request, string $alias, #[Vars] OAuthIntegration $oauth)
    {
        $org = $request->request->get('org');
        if (!$this->isCsrfTokenValid('token', $request->request->get('token')) || false === $this->canEdit($oauth)) {
            throw $this->createAccessDeniedException();
        }

        $client = $this->getClient($alias, $oauth);
        $oauth->setConnected($org, $connected = !$oauth->isConnected($org));


        $response = ['connected' => $connected];
        try {
            $status = $connected ? $client->addOrgHook($oauth, $org) : $client->removeOrgHook($oauth, $org);
            if (is_array($status)) {
                $oauth->setWebhookInfo($org, $status);
                $response += $status;
            }
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), ['e' => $e]);
            $response += ['error' => AppUtils::castError($e), 'code' => 409];
        }

        $this->registry->getManager()->flush();
        return new JsonResponse($response, $response['code'] ?? 200);
    }

    #[Route('/all/{id}/flush', name: 'integration_flush_cache', methods: ['POST'], format: 'json')]
    public function flushCache(#[Vars] OAuthIntegration $oauth)
    {
        $client = $this->getClient($oauth);
        $client->cacheClear($oauth->getId(), true);

        return new JsonResponse([], 204);
    }

    #[Route('/{alias}/{id}/regenerate', name: 'integration_regenerate')]
    public function regenerateAction(Request $request, string $alias, #[Vars] OAuthIntegration $oauth, OAuth2State $state): Response
    {
        if (!$this->isCsrfTokenValid('token', $request->query->get('_token')) || !$this->canEdit($oauth)) {
            return new Response('Invalid Csrf Params', 400);
        }

        $this->getClient($alias, $oauth)->cacheClear($oauth->getId());
        $response = new RedirectResponse($this->generateUrl('oauth_integration', ['alias' => $alias]));
        $state->set('_regenerate_id', $oauth->getId());
        $state->save($response);

        return $response;
    }

    #[Route('/{alias}/{id}/delete', name: 'integration_delete', methods: ['DELETE', 'POST'])]
    public function deleteAction(Request $request, string $alias, #[Vars] OAuthIntegration $oauth): Response
    {
        if (!$this->isCsrfTokenValid('delete', $request->request->get('_token')) || !$this->canDelete($oauth)) {
            return new Response('Invalid Csrf Form', 400);
        }

        $client = $this->getClient($alias, $oauth);
        foreach ($oauth->getEnabledOrganizations() as $organization) {
            try {
                $client->removeOrgHook($oauth, $organization);
            } catch (\Throwable $e) {}
        }

        $em = $this->registry->getManager();
        $em->remove($oauth);
        $em->flush();

        return new RedirectResponse($this->generateUrl('integration_list'));
    }

    protected function canDelete(OAuthIntegration $oauth): bool
    {
        return !$this->registry->getRepository(Package::class)->findOneBy(['integration' => $oauth]);
    }

    protected function canEdit(OAuthIntegration $oauth): bool
    {
        return $oauth->getOwner() === null || $oauth->getOwner() === $this->getUser()?->getUserIdentifier();
    }

    protected function getClient($alias, OAuthIntegration $oauth = null): AppInterface
    {
        if ($alias instanceof OAuthIntegration) {
            $oauth = $alias;
            $alias = $oauth->getAlias();
        }

        if (null !== $oauth && $oauth->getAlias() !== $alias) {
            throw $this->createNotFoundException();
        }

        try {
            return $this->integrations->findApp($alias);
        } catch (NotFoundAppException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }
}
