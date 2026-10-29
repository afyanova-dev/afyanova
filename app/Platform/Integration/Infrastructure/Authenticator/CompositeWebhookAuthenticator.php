<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Authenticator;

use App\Platform\Integration\Contracts\Port\WebhookAuthenticatorPort;
use App\Platform\Integration\Domain\Enum\AuthType;
use App\Platform\Integration\Domain\Model\IntegrationConnector;
use App\Platform\Integration\Domain\Model\IntegrationCredential;

final readonly class CompositeWebhookAuthenticator implements WebhookAuthenticatorPort
{
    public function __construct(
        private HmacSha256Authenticator $hmacAuthenticator,
        private BearerTokenAuthenticator $bearerAuthenticator,
    ) {}

    public function authenticate(IntegrationCredential $credential, array $headers, string $rawBody): bool
    {
        /** @var IntegrationConnector|null $connector */
        $connector = $credential->connector;
        $authType = $connector?->auth_type;

        return match ($authType) {
            AuthType::HMAC_SHA256 => $this->hmacAuthenticator->authenticate($credential, $headers, $rawBody),
            AuthType::BEARER_TOKEN, AuthType::API_KEY, AuthType::BASIC_AUTH => $this->bearerAuthenticator->authenticate($credential, $headers, $rawBody),
            default => false,
        };
    }
}
