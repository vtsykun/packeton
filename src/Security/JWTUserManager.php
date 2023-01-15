<?php

declare(strict_types=1);

namespace Packeton\Security;

use Packeton\Model\JWTUser;
use Symfony\Component\Security\Core\User\UserInterface;

class JWTUserManager
{
    private const JWT_PREFIX = 'pk_jwt_';

    public function __construct(
        private readonly JWSTokenProvider $tokenProvider,
    ) {}

    public function createTokenForUser(UserInterface $user, array $extra = null): string
    {
        $payload = [$user->getUserIdentifier(), $user->getRoles(), $extra];

        return 'pk_jwt_' . $this->tokenProvider->create($payload);
    }

    public function convertToJwtUser(UserInterface $user): UserInterface
    {
        return new JWTUser($user->getUserIdentifier(), $user->getRoles());
    }

    public function loadUserFromToken(string $token): JWTUser
    {
        $token = substr($token, strlen(self::JWT_PREFIX));

        [$username, $roles] = $this->tokenProvider->decode($token);

        return new JWTUser($username, $roles);
    }

    public function checkTokenFormat(?string $token): bool
    {
        return $token && str_starts_with($token, self::JWT_PREFIX) && strlen($token) > 32;
    }
}
