<?php

declare(strict_types=1);

namespace Packeton\Integrations\Security;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\User;
use Packeton\Integrations\IntegrationRegistry;
use Packeton\Integrations\LoginInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    public function __construct(
        protected IntegrationRegistry $integrations,
        protected ManagerRegistry $registry,
        protected LoggerInterface $logger
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
        $config->overwriteRoles();
        $repo = $this->registry->getRepository(User::class);
        $user = $repo->findByOAuth2Data($data);
        if ($config->hasLoginExpression()) {
            $result = $client->evaluateExpression(['user' => $user, 'data' => $data]);
            if (empty($result)) {
                throw new CustomUserMessageAuthenticationException('Login is not allowed by custom rules');
            }

            if (is_array($result) ) {
                $probe = $result[0] ?? null;
                if (!is_string($probe) || !str_starts_with($probe, 'ROLE_')) {
                    $this->logger->error("OAuth2 expression error, return result must be list of a valid roles");
                    throw new CustomUserMessageAuthenticationException('OAuth2 login failed by invalid expression configuration');
                }

                $config->overwriteRoles($result);
            }
        }

        if ($user === null) {
            if (!$config->isRegistration()) {
                throw new CustomUserMessageAuthenticationException('Registration is not allowed');
            }

            $em = $this->registry->getManager();

            $user = $client->createUser($data);
            $em->persist($user);
            $em->flush();
        }

        $config->overwriteRoles();
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
<script src="/packeton/js/redirect.js"></script>
</body>
</html>
TXT;
        return $text;
    }
}
