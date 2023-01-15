<?php

declare(strict_types=1);

namespace Packeton\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Sign and verify tokens service.
 *
 * Generate keys with openssl
 *
 * openssl ecparam -name prime256v1 -genkey -noout -out key.pem
 * openssl ec -in key.pem -pubout -out public.pem
 */
class JWSTokenProvider
{
    public static $supportedSodiumAlgos = [
        'EdDSA' => 1,
    ];

    public static $supportedOpenSSLAlgos = [
        'ES384' => \OPENSSL_KEYTYPE_EC,
        'ES256' => \OPENSSL_KEYTYPE_EC,
        'RS256' => \OPENSSL_KEYTYPE_RSA,
        'RS384' => \OPENSSL_KEYTYPE_RSA,
        'RS512' => \OPENSSL_KEYTYPE_RSA,
    ];

    public function __construct(
        private readonly array $jwtTokenConfig,
        private readonly string $jwtSignAlgorithm = 'RS256'
    ) {}

    public function create(array $jwtData): string
    {
        $key = $this->createSignKey();

        return JWT::encode($jwtData, $key, $this->jwtSignAlgorithm);
    }

    public function decode(string $jwtToken): array
    {
        $key = $this->createSignKey(false);

        $payload = JWT::decode($jwtToken, new Key($key, $this->jwtSignAlgorithm));

        return json_decode(json_encode($payload), true);
    }

    private function createSignKey(bool $isPrivate = true): mixed
    {
        $keyName = $isPrivate ? 'private_key' : 'public_key';
        if (empty($this->jwtTokenConfig[$keyName])) {
            throw new \InvalidArgumentException($keyName . ' is not setup, please update your configuration packeton->jwt_authentication');
        }

        $algo = $this->jwtSignAlgorithm;
        $rawKey = @is_file($this->jwtTokenConfig[$keyName]) ?
            file_get_contents($this->jwtTokenConfig[$keyName]) : $this->jwtTokenConfig[$keyName];

        if ($isPrivate && isset(self::$supportedOpenSSLAlgos[$algo])) {
            $key = openssl_pkey_get_private($rawKey, $this->config['passphrase'] ?? null);
            if (false === $key) {
                throw new \UnexpectedValueException('Private key is not a valid, please check packeton->jwt_authentication: ' . openssl_error_string());
            }

            $publicKey = openssl_pkey_get_details($key);

            if (!isset($publicKey['key']) || $publicKey['type'] !== self::$supportedOpenSSLAlgos[$algo]) {
                throw new \UnexpectedValueException('Invalid private key type, key type must compatible with sign algo ' . $algo);
            }
        } else if (isset(self::$supportedSodiumAlgos[$algo])) {
            $key = $rawKey; // must be in base64
        } else {
            $key = $rawKey;
        }

        return $key;
    }
}
