<?php

declare(strict_types=1);

namespace Packeton\DBAL;

class OpensslCrypter
{
    public function __construct(protected string $encryptionDbalKey, protected string $algo = 'aes-256-cbc')
    {
    }

    public function encryptData(string $data): ?string
    {
        $ivLength = openssl_cipher_iv_length($this->algo);
        $iv = openssl_random_pseudo_bytes($ivLength);
        if (false === ($encryptedData = @openssl_encrypt($data, $this->algo, $this->encryptionDbalKey, OPENSSL_RAW_DATA, $iv))) {
            return null;
        }

        $metadata = [
            'ivLength' => $ivLength,
            'algo' => $this->algo,
        ];

        return base64_encode(json_encode($metadata) . "\n" . $iv . $encryptedData);
    }

    public function isEncryptData(string $value): bool
    {
        return (bool) $this->getEncryptMetadata($value);
    }

    public function decryptData(string $value): ?string
    {
        if (!$metadata = $this->getEncryptMetadata($value)) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length($metadata['algo']);

        $iv = substr($metadata['data'], 0, $ivLength);
        $data = substr($metadata['data'], $ivLength);
        return @openssl_decrypt($data, $metadata['algo'], $this->encryptionDbalKey, OPENSSL_RAW_DATA, $iv) ?: null;
    }

    private function getEncryptMetadata(string $value): array|false
    {
        if (!$data = @base64_decode($value) or count($data = explode("\n", $data, 2)) < 2) {
            return false;
        }

        [$metadata, $data] = $data;
        try {
            $metadata = @json_decode($metadata, true);
        } catch (\Throwable) {
            return false;
        }

        if (!isset($metadata['algo'])) {
            return false;
        }

        return $metadata + ['data' => $data];
    }
}
