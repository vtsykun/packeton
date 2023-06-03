<?php

declare(strict_types=1);

namespace Packeton\Controller\OAuth;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\OAuthIntegration;
use Packeton\Integrations\AppInterface;
use Packeton\Integrations\Exception\NotFoundAppException;
use Packeton\Integrations\IntegrationRegistry;
use Packeton\Integrations\LoginInterface;
use Packeton\Integrations\Model\OAuth2State;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class OAuthController extends AbstractController
{
    public function __construct(
        protected IntegrationRegistry $integrations,
        protected ManagerRegistry $registry,
        protected OAuth2State $state
    ) {
    }

    #[Route('/oauth2/{alias}', name: 'oauth_login')]
    public function oauthLogin(string $alias, Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirect('/');
        }

        return $this->findLogin($alias)->redirectOAuth2Url($request);
    }

    #[Route('/oauth2/{alias}/check', name: 'oauth_check')]
    public function oauthCheck(string $alias): Response
    {
        throw new \LogicException("Oauth must be checked by OAuth2Authenticator [$alias]");
    }

    #[Route('/oauth2/{alias}/setup', name: 'oauth_integration')]
    #[IsGranted('ROLE_ADMIN')]
    public function integration(string $alias, Request $request): Response
    {
        $this->state->set('username', $this->getUser()->getUserIdentifier());
        $this->state->save();

        return $this->findApp($alias)->redirectOAuth2App($request);
    }

    #[Route('/oauth2/{alias}/install', name: 'oauth_install')]
    public function install(string $alias, Request $request): Response
    {
        $integration = $this->findApp($alias);
        $accessToken = $integration->getAccessToken($request, ['app' => true]);

        $owner = $this->getUser()?->getUserIdentifier() ?: $this->state->get('username');

        $oauth = new OAuthIntegration();
        $oauth->setOwner($owner)
            ->setAccessToken($accessToken)
            ->setHookSecret(sha1(random_bytes(20)))
            ->setAlias($alias);

        $em = $this->registry->getManager();
        $em->persist($oauth);
        $em->flush();

        $route = $this->generateUrl('integration_index', ['id' => $oauth->getId(), 'alias' => $alias]);
        if (null === $this->getUser()) {
            // Save redirect without session
            $session = $request->getSession();
            $request->cookies->set($session->getName(), $session->getId());
            return $this->render('user/redirect.html.twig', ['route' => $route]);
        }

        return $this->redirect($route);
    }

    protected function findLogin(string $alias): LoginInterface
    {
        try {
            return $this->integrations->findLogin($alias);
        } catch (NotFoundAppException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    protected function findApp(string $alias): AppInterface
    {
        try {
            return $this->integrations->findApp($alias);
        } catch (NotFoundAppException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }
}
