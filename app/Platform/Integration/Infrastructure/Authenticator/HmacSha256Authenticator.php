<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Authenticator;

use App\Platform\Integration\Contracts\Port\SecretResolverPort;
use App\Platform\Integration\Contracts\Port\WebhookAuthenticatorPort;
use App\Platform\Integration\Domain\Model\IntegrationCredential;

final readonly class HmacSha256Authenticator implements WebhookAuthenticatorPort
{
    public function __construct(
        private SecretResolverPort $secretResolver,
    ) {}

    public function authenticate(IntegrationCredential $credential, array $headers, string $rawBody): bool
    {
        $secret = $this->secretResolver->resolve($credential->secret_ref);
        if ($secret === null || $secret === '') {
            return false;
        }

        $signature = $this->extractSignature($headers);
        if ($signature === null || $signature === '') {
            return false;
        }

        // Strip prefix like "sha256=" if present
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        $computed = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($computed, $signature);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function extractSignature(array $headers): ?string
    {
        // Normalize headers to lowercase keys
        $normalized = [];
        foreach ($headers as $key => $val) {
            $normalized[strtolower((string) $key)] = is_array($val) ? ($val[0] ?? null) : $val;
        }

        $candidates = ['x-signature', 'x-hmac-sha256', 'x-hub-signature-256', 'signature'];

        foreach ($candidates as $candidate) {
            if (isset($normalized[$candidate]) && is_string($normalized[$candidate])) {
                return trim($normalized[$candidate]);
            }
        }

        return null;
    }
}
