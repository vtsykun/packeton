<?php

declare(strict_types=1);

namespace Packeton\Integrations\Security;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\User;
use Packeton\Integrations\IntegrationRegistry;
use Packeton\Integrations\LoginInterface;
use Packeton\Trait\RequestContextTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class OAuth2Authenticator implements InteractiveAuthenticatorInterface
{
    use RequestContextTrait;

    public function __construct(
        protected IntegrationRegistry $integrations,
        protected ManagerRegistry $registry,
        protected LoggerInterface $logger,
        protected RequestContext $requestContext,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function supports(Request $request): ?bool
    {
        if ($request->attributes->get('_route') === 'oauth_check') {
            $client = $request->attributes->get('alias', '__unset');
            try {
                return $this->integrations->findLogin($client) !== null;
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(Request $request): Passport
    {
        $client = $request->attributes->get('alias');
        $client = $this->integrations->findLogin($client);

        try {
            $data = $client->fetchUser($request);
        } catch (\Exception $e) {
            if ($e instanceof ClientException) {
                $msg = $e->getResponse()->getContent(false);
                $msg = substr($msg, 0, 512);
            } else {
                $msg = $e->getMessage();
            }

            $this->logger->error($msg, ['e' => $e]);
            throw new CustomUserMessageAuthenticationException('Unable to fetch oauth data');
        }

        if (empty($data['_type'])) {
            $this->logger->error("oauth2 authenticator error, client response must contains _type of user_identifier field.", ['user' => $data]);
            throw new CustomUserMessageAuthenticationException('Invalid oauth user data. Client response must contains _type of user identifier');
        }

        $badges = [];
        if (filter_var($request->cookies->get('_remember_me_flag'), FILTER_VALIDATE_BOOL)) {
            $badges[] = new RememberMeBadge();
            $request->request->set('_remember_me', 'on');
        }

        return new SelfValidatingPassport(new UserBadge($data['user_identifier'], fn () => $this->loadOrCreateUser($client, $data)), $badges);
    }

    protected function loadOrCreateUser(LoginInterface $client, array $data): User
    {
        $config = $client->getConfig();
        $repo = $this->registry->getRepository(User::class);
        $user = $repo->findByOAuth2Data($data);

        $em = $this->registry->getManager();
        if ($user === null) {
            if (!$config->isRegistration()) {
                throw new CustomUserMessageAuthenticationException('Registration is not allowed');
            }
            $user = $client->createUser($data);
        }

        if ($config->hasLoginExpression()) {
            $result = $client->evaluateExpression(['user' => $user, 'data' => $data]);
            if (empty($result)) {
                throw new CustomUserMessageAuthenticationException('Login is not allowed by custom rules');
            }

            if (null === $user->getId() && is_array($result) && is_string($probe = $result[0] ?? null) && str_starts_with($probe, 'ROLE_')) {
                $user->setRoles($result);
            }
        }

        if (null === $user->getId()) {
            $em->persist($user);
            $em->flush();
        }

        return $user;
    }

    /**
     * {@inheritdoc}
     */
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        return new UsernamePasswordToken($passport->getUser(), $firewallName, $passport->getUser()->getRoles());
    }

    /**
     * {@inheritdoc}
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new Response($this->getJSRedirectTemplate('/'));
    }

    /**
     * {@inheritdoc}
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('error', $exception->getMessage());
        }

        return new Response($this->getJSRedirectTemplate('/login'));
    }

    /**
     * {@inheritdoc}
     */
    public function isInteractive(): bool
    {
        return true;
    }

    // Workaround when session is strict
    // Used to remove referral header.
    protected function getJSRedirectTemplate($route): string
    {
        $path = $this->generateUrl('/packeton/js/redirect.js');
        $route = $this->generateUrl($route);

        $text = <<<TXT
<html lang="en">
<head>
<title>Redirecting...</title>
</head>
<body>
<script></script>
<div>
<p>Processing redirect <a id="route" href="$route">$route</a></p>
</div>
<script src="$path"></script>
</body>
</html>
TXT;
        return $text;
    }
}
