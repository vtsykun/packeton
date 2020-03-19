<?php

namespace Packagist\WebBundle\Security\Api;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Security\Core\Authentication\AuthenticationManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Firewall\ListenerInterface;

class ApiBasicAuthenticationListener implements ListenerInterface
{
    private $tokenStorage;
    private $authenticationManager;
    private $providerKey;
    private $authenticationEntryPoint;
    private $logger;
    private $ignoreFailure;

    public function __construct(TokenStorageInterface $tokenStorage, AuthenticationManagerInterface $authenticationManager, $providerKey, AuthenticationEntryPointInterface $authenticationEntryPoint = null, LoggerInterface $logger = null)
    {
        if (empty($providerKey)) {
            throw new \InvalidArgumentException('$providerKey must not be empty.');
        }

        $this->tokenStorage = $tokenStorage;
        $this->authenticationManager = $authenticationManager;
        $this->providerKey = $providerKey;
        $this->authenticationEntryPoint = $authenticationEntryPoint ?: new ApiBasicAuthenticationEntryPoint();
        $this->logger = $logger ?: new NullLogger();
        $this->ignoreFailure = false;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(GetResponseEvent $event)
    {
        $request = $event->getRequest();
        if ($username = $request->headers->get('PHP_AUTH_USER')) {
            $credentials = $request->headers->get('PHP_AUTH_PW');
            $this->logger->info('Basic authentication Authorization header found for user.', ['username' => $username]);
        } elseif ($request->query->get('apiToken') && $request->query->get('username')) {
            $credentials = $request->query->get('apiToken');
            $username = $request->query->get('username');
        } elseif ($username = $request->query->get('token')) {
            $username = \explode(':', $username);
            if (2 !== \count($username)) {
                return;
            }

            list($username, $credentials) = $username;
            $this->logger->info('Api authorization header found for user.', ['username' => $username]);
        } else {
            return;
        }

        if (null !== $token = $this->tokenStorage->getToken()) {
            if ($token instanceof TokenStorageInterface && $token->isAuthenticated() && $token->getUsername() === $username) {
                return;
            }
        }

        try {
            $token = $this->authenticationManager->authenticate(new ApiUsernamePasswordToken($username, $credentials, $this->providerKey));
            $this->tokenStorage->setToken($token);
        } catch (AuthenticationException $e) {
            $token = $this->tokenStorage->getToken();
            if ($token instanceof ApiUsernamePasswordToken && $this->providerKey === $token->getProviderKey()) {
                $this->tokenStorage->setToken(null);
            }

            if (null !== $this->logger) {
                $this->logger->info('Basic authentication failed for user.', ['username' => $username, 'exception' => $e]);
            }

            if ($this->ignoreFailure) {
                return;
            }

            $event->setResponse($this->authenticationEntryPoint->start($request, $e));
        }
    }
}
