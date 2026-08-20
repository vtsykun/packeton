<?php

declare(strict_types=1);

namespace Packeton\Service;

class WebhookSignatureValidator
{
    public const SIGNATURE_HEADER = 'X-Hub-Signature-256';

    /**
     * @param array<int, string> $secrets
     */
    public function findMatchingSecretId(string $payload, string $signature, array $secrets): ?int
    {
        if (1 !== preg_match('/\Asha256=([A-Fa-f0-9]{64})\z/', $signature, $matches)) {
            return null;
        }

        $providedHash = strtolower($matches[1]);
        foreach ($secrets as $id => $secret) {
            if (hash_equals(hash_hmac('sha256', $payload, $secret), $providedHash)) {
                return $id;
            }
        }

        return null;
    }
}
