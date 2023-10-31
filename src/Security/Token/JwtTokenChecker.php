<?php

declare(strict_types=1);

namespace Packeton\Security\Token;

use Packeton\Entity\User;
use Packeton\Security\JWTUserManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserInterface;

class JwtTokenChecker implements TokenCheckerInterface
{
    public function __construct(
        private readonly JWTUserManager $jwtManager,
        private readonly FastTokenCache $cache
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function support(string $username, string $token): bool
    {
        return $this->jwtManager->checkTokenFormat($token);
    }

    /**
     * {@inheritdoc}
     */
    public function loadUserByToken(string $username, string $token, Request $request, callable $userLoader): UserInterface
    {
        if ($user = $this->cache->hit($username, $token)) {
            return $user;
        }

        try {
            $tokenUser = $this->jwtManager->loadUserFromToken($token);
        } catch (\Exception $e) {
            throw new BadCredentialsException('Bad credentials', 0, $e);
        }

        $user = $userLoader($username);
        if (!$user instanceof UserInterface) {
            throw new BadCredentialsException('Bad credentials');
        }

        $jwtUser = $this->jwtManager->convertToJwtUser($user);
        if (!$jwtUser->isEqualUserAttributes($tokenUser)) {
            throw new BadCredentialsException('Token is not belong to this user, please update a token after roles changes');
        }
        if ($user instanceof User) {
            return $user;
        }

        $this->cache->save($jwtUser, $username, $token);
        return $jwtUser;
    }
}
