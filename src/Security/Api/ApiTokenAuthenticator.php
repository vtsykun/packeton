<?php

declare(strict_types=1);

namespace Packeton\Security\Api;

use Packeton\Entity\User;
use Packeton\Security\Provider\AuditSessionProvider;
use Packeton\Security\Token\PatTokenCheckerInterface;
use Packeton\Security\Token\TokenCheckerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class ApiTokenAuthenticator implements AuthenticatorInterface, AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly UserProviderInterface $userProvider,
        private readonly UserCheckerInterface $userChecker,
        private readonly TokenCheckerRegistry $tokenCheckerRegistry,
        private readonly AuditSessionProvider $auditSessionProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param ApiUsernamePasswordToken $token
     *
     * {@inheritdoc}
     */
    public function authenticate(Request $request): Passport
    {
        if (!$credentials = $this->getCredentials($request)) {
            throw new AuthenticationException('The token is not supported by this authentication provider.');
        }

        [$username, $token] = $credentials;

        return new SelfValidatingPassport(
            new UserBadge($username, fn() => $this->validateAndLoadUser($username, $token, $request)),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $message = $authException->getMessage();
        if ($authException instanceof AuthenticationCredentialsNotFoundException) {
            $message = 'Authorization Required';
        }

        return new JsonResponse(['status' => 'error', 'message' => $message], 401);
    }

    public function validateAndLoadUser(string $username, string $token, Request $request): UserInterface|User
    {
        $checker = $this->tokenCheckerRegistry->getTokenChecker($username, $token);
        $auditUsername = null;
        $errorMsg = null;

        try {
            $user = $checker->loadUserByToken($username, $token, $request, function (string|null $username) use (&$auditUsername) {
                if (empty($username)) {
                    return null;
                }

                $user = $this->userProvider->loadUserByIdentifier($username);
                $auditUsername = $username;

                $this->userChecker->checkPreAuth($user);
                return $user;
            });

            if ($checker instanceof PatTokenCheckerInterface) {
                $checker->checkAccess($request, $user);
            }
        } catch (UserNotFoundException $e) {
            $errorMsg = $e->getMessage();
            throw new BadCredentialsException('Bad credentials.', 0, $e);
        } catch (AuthenticationException $e) {
            $errorMsg = $e->getMessage();
            throw $e;
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            throw new AuthenticationServiceException($e->getMessage(), 0, $e);
        } finally {
            try {
                if (null !== $auditUsername) {
                    $this->auditSessionProvider->logApi($request, $auditUsername, $token, $errorMsg);
                }
            } catch (\Throwable $e) {}
        }

        return $user;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(Request $request): bool
    {
        if ($request->headers->has('PHP_AUTH_USER')
            || ($request->query->get('apiToken') && $request->query->get('username'))
        ) {
            return true;
        }

        if ($username = $request->query->get('token')) {
            $token = \explode(':', $username);
            return 2 === \count($token) || strlen($username) > 32;
        }

        return false;
    }

    private function getCredentials(Request $request)
    {
        if ($username = $request->headers->get('PHP_AUTH_USER')) {
            $credentials = $request->headers->get('PHP_AUTH_PW');
            $this->logger->info('Basic authentication Authorization header found for user.', ['username' => $username]);
        } elseif ($request->query->get('apiToken') && $request->query->get('username')) {
            $credentials = $request->query->get('apiToken');
            $username = $request->query->get('username');
        } elseif ($username = $request->query->get('token')) {
            $token = \explode(':', $username);
            if (2 === \count($token)) {
                [$username, $credentials] = $token;
            } else {
                [$username, $credentials] = ['token', $username];
            }

            $this->logger->info('Api authorization header found for user.', ['username' => $username]);
        } else {
            return null;
        }

        return [$username, $credentials];
    }

    /**
     * {@inheritdoc}
     */
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        return new ApiUsernamePasswordToken($passport->getUser(), $firewallName, $passport->getUser()->getRoles());
    }

    /**
     * @param Passport $passport
     * {@inheritdoc}
     */
    public function createAuthenticatedToken($passport, string $firewallName): TokenInterface
    {
        return $this->createToken($passport, $firewallName);
    }

    /**
     * {@inheritdoc}
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->logger->info('Composer authentication failed for user.', ['exception' => $exception]);

        return $this->start($request, $exception);
    }
}
