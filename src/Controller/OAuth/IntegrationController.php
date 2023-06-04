<?php

declare(strict_types=1);

namespace Packeton\Controller\OAuth;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Composer\JsonResponse;
use Packeton\Entity\OAuthIntegration;
use Packeton\Form\Type\IntegrationSettingsType;
use Packeton\Integrations\Exception\NotFoundAppException;
use Packeton\Integrations\IntegrationRegistry;
use Packeton\Integrations\AppInterface;
use Packeton\Integrations\Model\IntegrationUtils;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        return $this->render('integration/list.html.twig', ['integrations'  => $integrations]);
    }

    #[Route('/connect', name: 'integration_connect')]
    public function connect(): Response
    {
        $integrations = $this->integrations->findAllApps();
        return $this->render('integration/connect.html.twig', ['integrations'  => $integrations]);
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
            $errorMsg = $e->getMessage();
        }

        $config = $client->getConfig($oauth);

        return $this->render('integration/index.html.twig', [
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
        $repos = $client->repositories($oauth);

        $form = $this->createForm(IntegrationSettingsType::class, $oauth, ['repos' => $repos]);
        $form->handleRequest($request);
        $config = $client->getConfig($oauth);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->registry->getManager()->flush();
            $this->addFlash('success', 'The integration settings has been updated.');
            return $this->redirect($this->generateUrl('integration_index', ['alias' => $alias, 'id' => $oauth->getId()]));
        }

        return $this->render('integration/settings.html.twig', [
            'form'    => $form->createView(),
            'client' => $client,
            'alias' => $alias,
            'config' => $config,
            'oauth' => $oauth,
        ]);
    }

    #[Route('/{alias}/{id}/connect', name: 'integration_connect_org')]
    public function connectOrg(Request $request, string $alias, #[Vars] OAuthIntegration $oauth)
    {
        $org = $request->request->get('org');
        if (!$this->isCsrfTokenValid('token', $request->request->get('token'))) {
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
            $response += ['error' => IntegrationUtils::castError($e), 'code' => 409];
        }

        $this->registry->getManager()->flush();
        return new JsonResponse($response, $response['code'] ?? 200);
    }

    protected function getClient($alias, OAuthIntegration $oauth = null): AppInterface
    {
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
