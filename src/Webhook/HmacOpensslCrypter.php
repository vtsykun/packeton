<?php

declare(strict_types=1);

namespace Packeton\Webhook;

use Packeton\DBAL\OpensslCrypter;

/**
 * OpensslCrypter decorator
 */
class HmacOpensslCrypter
{
    public function __construct(
        private readonly OpensslCrypter $crypter,
        private readonly string $signKey,
        private readonly string $hmacAlgo = 'sha512',
    ) {}

    public function decryptData(string $value): ?string
    {
        if (!$this->hmacVerify($value)) {
            return null;
        }

        [$data] = $this->removeHmac($value);

        return $this->crypter->decryptData($data);
    }

    public function isEncryptData(string $value): bool
    {
        if (!$this->hmacVerify($value)) {
            return false;
        }

        [$data] = $this->removeHmac($value);

        return $this->crypter->isEncryptData($data);
    }

    public function encryptData(string $data): ?string
    {
        if ($encryptedData = $this->crypter->encryptData($data)) {
            $hmac = hash_hmac($this->hmacAlgo, $encryptedData, $this->signKey, true);
            return base64_encode($hmac . $encryptedData);
        }

        return $encryptedData;
    }

    private function removeHmac(string $data): array
    {
        $data = base64_decode($data);
        $hashLength = strlen(hash($this->hmacAlgo, $data, true));

        $hmac = substr($data, 0, $hashLength);
        $data = substr($data, $hashLength);

        return [$data, $hmac];
    }

    private function hmacVerify(string $data): bool
    {
        [$data, $hmac] = $this->removeHmac($data);

        return hash_equals(hash_hmac($this->hmacAlgo, (string)$data, $this->signKey, true), $hmac);
    }
}
