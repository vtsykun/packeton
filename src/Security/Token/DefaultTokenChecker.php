<?php

namespace Packeton\Security\Token;

use Packeton\Entity\User;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserInterface;

class DefaultTokenChecker implements TokenCheckerInterface
{
    /**
     * {@inheritdoc}
     */
    public function support(string $username, string $token): bool
    {
        return true;
    }

    public function loadUserByToken(string $username, string $token, callable $userLoader): UserInterface
    {
        $user = $userLoader($username);

        if (!$user instanceof User
            || empty($user->getApiToken())
            || \strlen($user->getApiToken()) <= 6
            || $user->getApiToken() !== $token
        ) {
            throw new BadCredentialsException('Bad credentials');
        }

        return $user;
    }
}
