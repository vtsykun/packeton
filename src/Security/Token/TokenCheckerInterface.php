<?php

declare(strict_types=1);

namespace Packeton\Security\Token;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

interface TokenCheckerInterface
{
    /**
     * Check if token prefix is supported
     *
     * @param string $username
     * @param string $token
     *
     * @return bool
     */
    public function support(string $username, string $token): bool;

    /**
     * Load and validate credentials.
     *
     * @param callable $userLoader
     * @param string $username
     * @param string $token
     *
     * @throws UserNotFoundException
     * @throws BadCredentialsException
     *
     * @return UserInterface
     */
    public function loadUserByToken(string $username, string $token, callable $userLoader): UserInterface;
}
