<?php

declare(strict_types=1);

namespace Packeton\Security\Token;

use Packeton\Webhook\HmacOpensslCrypter;
use Symfony\Component\Security\Core\User\UserInterface;

class FastTokenCache
{
    private const MAX_TTL = 60; // 60 sec

    public function __construct(
        private readonly \Redis $redis,
        private readonly HmacOpensslCrypter $crypter
    ) {
    }

    public function hit(string $username, string $token): ?UserInterface
    {
        $cacheKey = $this->getKey($username, $token);
        if (!$jwtSerializedData = $this->redis->get($cacheKey)) {
            return null;
        }

        try {
            $serialized = $this->crypter->decryptData($jwtSerializedData);
            [$user, $timestamp] = $serialized ? unserialize($serialized) : [null, 0];
        } catch (\Throwable) {
            return null;
        }

        if (time() - $timestamp < self::MAX_TTL) {
            return $user;
        }

        return null;
    }

    public function save(UserInterface $user, string $username, string $token): void
    {
        $cacheKey = $this->getKey($username, $token);
        $jwtSerializedData = $this->crypter->encryptData(serialize([$user, time()]));

        $this->redis->setex($cacheKey, self::MAX_TTL, $jwtSerializedData);
    }

    private function getKey(string $username, string $token): string
    {
        return 'user-api-' . sha1($username . "\x00" . $token);
    }
}
