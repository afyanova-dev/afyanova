<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Authenticator;

use App\Platform\Integration\Contracts\Port\SecretResolverPort;
use App\Platform\Integration\Contracts\Port\WebhookAuthenticatorPort;
use App\Platform\Integration\Domain\Model\IntegrationCredential;

final readonly class BearerTokenAuthenticator implements WebhookAuthenticatorPort
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

        $token = $this->extractToken($headers);
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals($secret, $token);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function extractToken(array $headers): ?string
    {
        $normalized = [];
        foreach ($headers as $key => $val) {
            $normalized[strtolower((string) $key)] = is_array($val) ? ($val[0] ?? null) : $val;
        }

        if (isset($normalized['authorization']) && is_string($normalized['authorization'])) {
            $auth = trim($normalized['authorization']);
            if (str_starts_with(strtolower($auth), 'bearer ')) {
                return trim(substr($auth, 7));
            }
        }

        if (isset($normalized['x-api-key']) && is_string($normalized['x-api-key'])) {
            return trim($normalized['x-api-key']);
        }

        return null;
    }
}
