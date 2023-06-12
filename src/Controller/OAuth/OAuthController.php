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

        $this->state->set('controller', 'oauth_check');

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
        $this->state->set('controller', 'oauth_install');
        $this->state->save();

        return $this->findApp($alias)->redirectOAuth2App($request);
    }

    #[Route('/oauth2/{alias}/auto', name: 'oauth_auto_redirect')]
    public function autoRedirect(string $alias, Request $request): Response
    {
        if (!$action = $this->state->get('controller')) {
            throw $this->createNotFoundException("State parameter is lost, please check why oauth2_state cookie is lost");
        }

        if (null === $this->getUser()) {
            // safe redirect without usage session when same site = strict
            $session = $request->getSession();
            $request->cookies->set($session->getName(), $session->getId());
        }
        $query = $request->query->all();
        $route = $this->generateUrl($action, ['alias' => $alias] + $query);

        return $this->render('user/redirect.html.twig', ['route' => $route]);
    }

    #[Route('/oauth2/{alias}/install', name: 'oauth_install')]
    public function install(string $alias, Request $request): Response
    {
        $app = $this->findApp($alias);
        $accessToken = $app->getAccessToken($request, ['app' => true]);

        $owner = $this->getUser()?->getUserIdentifier() ?: $this->state->get('username');

        $oauth = ($id = $this->state->get('_regenerate_id')) ?
            $this->registry->getRepository(OAuthIntegration::class)->find((int) $id) : null;

        if (null === $oauth) {
            $oauth = new OAuthIntegration();
            $oauth->setOwner($owner)
                ->setHookSecret(sha1(random_bytes(20)))
                ->setAlias($alias);
        }

        $oauth->setAccessToken($accessToken);

        try {
            if ($app instanceof LoginInterface) {
                $user = $app->fetchUser($accessToken);
                $oauth->setLabel($user['user_name'] ?? null);
            }
        } catch (\Throwable $e) {
        }

        $em = $this->registry->getManager();
        $em->persist($oauth);
        $em->flush();

        $this->state->set('_regenerate_id', null);
        $this->state->save();

        $route = $this->generateUrl('integration_index', ['id' => $oauth->getId(), 'alias' => $alias]);
        if (null === $this->getUser()) {
            // safe redirect without usage session when same site = strict
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
