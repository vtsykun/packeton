<?php

declare(strict_types=1);

namespace Packeton\Security\Token;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;

interface PatTokenCheckerInterface
{
    /**
     * Checks the user account before authentication.
     *
     * @throws AuthenticationException
     */
    public function checkAccess(Request $request, UserInterface $user): void;
}
