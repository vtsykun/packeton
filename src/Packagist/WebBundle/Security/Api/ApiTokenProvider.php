<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Security\Api;

use Packagist\WebBundle\Entity\User;
use Symfony\Component\Security\Core\Authentication\Provider\AuthenticationProviderInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class ApiTokenProvider implements AuthenticationProviderInterface
{
    private $userProvider;
    private $userChecker;

    public function __construct(UserProviderInterface $provider, UserCheckerInterface $userChecker)
    {
        $this->userProvider = $provider;
        $this->userChecker = $userChecker;
    }

    /**
     * @param ApiUsernamePasswordToken $token
     *
     * {@inheritdoc}
     */
    public function authenticate(TokenInterface $token)
    {
        if (!$this->supports($token)) {
            throw new AuthenticationException('The token is not supported by this authentication provider.');
        }

        $username = $token->getUsername();
        if ('' === $username || null === $username) {
            $username = 'NONE_PROVIDED';
        }

        $user = $this->retrieveUser($username, $token);
        if (\strlen($user->getApiToken()) > 6 && $user->getApiToken() === $token->getCredentials()) {
            $authenticatedToken = new ApiUsernamePasswordToken($user, $token->getCredentials(), $token->getProviderKey(), $user->getRoles());
            $authenticatedToken->setAttributes($token->getAttributes());
            return $authenticatedToken;
        }

        $e = new BadCredentialsException('Bad credentials.');
        $e->setToken($token);
        throw $e;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(TokenInterface $token)
    {
        return $token instanceof ApiUsernamePasswordToken;
    }

    /**
     * @param string $username
     * @param TokenInterface $token
     *
     * @return User
     */
    protected function retrieveUser(string $username, TokenInterface $token): User
    {
        $user = $token->getUser();
        if ($user instanceof User) {
            return $user;
        }

        try {
            $user = $this->userProvider->loadUserByUsername($username);
            $this->userChecker->checkPreAuth($user);
        } catch (UsernameNotFoundException $e) {
            throw new BadCredentialsException('Bad credentials.', 0, $e);
        } catch (\Exception $e) {
            $e = new AuthenticationServiceException($e->getMessage(), 0, $e);
            $e->setToken($token);
            throw $e;
        }

        if (!$user instanceof User) {
            throw new AuthenticationServiceException('The user provider must return a UserInterface object.');
        }

        return $user;
    }
}
