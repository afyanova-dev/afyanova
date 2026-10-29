<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Integration\Domain\Enum\AuthType;
use App\Platform\Integration\Domain\Enum\ConnectorDirection;
use App\Platform\Integration\Domain\Enum\ConnectorFamily;
use App\Platform\Integration\Domain\Enum\ConnectorStatus;
use App\Platform\Integration\Domain\Enum\CredentialAlgorithm;
use App\Platform\Integration\Domain\Enum\CredentialStatus;
use App\Platform\Integration\Domain\Enum\PayloadSensitivity;
use App\Platform\Integration\Domain\Model\IntegrationConnector;
use App\Platform\Integration\Domain\Model\IntegrationCredential;
use App\Platform\Integration\Domain\Model\WebhookEndpoint;
use App\Shared\Tenancy\TenantContextScope;

require_once __DIR__.'/security.php';

if (! function_exists('integrationFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @return array{tenant: Tenant, organization: Organization, facility: Facility, user: User, role_id: string}
     */
    function integrationFixture(?array $permissions = null): array
    {
        $permissions ??= [
            'integration.access',
            'integration.connector.manage',
            'integration.credential.rotate',
            'integration.messages.view',
            'integration.deadletter.resolve',
            'integration.mapping.manage',
            'integration.webhook.manage',
            'integration.import.reconcile',
        ];

        /** @var array{tenant: Tenant, organization: Organization, facility: Facility, user: User, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}

if (! function_exists('inTenantContext')) {
    function inTenantContext(string $tenantId, callable $fn): mixed
    {
        return TenantContextScope::run($tenantId, null, $fn);
    }
}

if (! function_exists('createTestConnector')) {
    function createTestConnector(
        string $tenantId,
        string $name = 'Test Connector',
        string $key = 'test-connector',
        ConnectorFamily $family = ConnectorFamily::OTHER,
        ConnectorDirection $direction = ConnectorDirection::BIDIRECTIONAL,
        AuthType $authType = AuthType::HMAC_SHA256,
        ConnectorStatus $status = ConnectorStatus::ENABLED,
    ): IntegrationConnector {
        $connector = new IntegrationConnector([
            'tenant_id' => $tenantId,
            'name' => $name,
            'key' => $key,
            'family' => $family,
            'direction' => $direction,
            'endpoint_config' => ['base_url' => 'https://api.external-partner.example.com'],
            'auth_type' => $authType,
            'payload_sensitivity' => PayloadSensitivity::STANDARD,
            'status' => $status,
        ]);
        $connector->save();

        return $connector;
    }
}

if (! function_exists('createTestCredential')) {
    function createTestCredential(
        IntegrationConnector $connector,
        string $secretRef = 'test_secret_key',
        CredentialAlgorithm $algorithm = CredentialAlgorithm::HMAC_SHA256,
        CredentialStatus $status = CredentialStatus::ACTIVE,
    ): IntegrationCredential {
        $credential = new IntegrationCredential([
            'tenant_id' => $connector->tenant_id,
            'connector_id' => $connector->id,
            'key_identifier' => 'test-key-'.bin2hex(random_bytes(4)),
            'algorithm' => $algorithm,
            'secret_ref' => $secretRef,
            'status' => $status,
        ]);
        $credential->save();

        return $credential;
    }
}

if (! function_exists('createTestWebhookEndpoint')) {
    function createTestWebhookEndpoint(
        IntegrationConnector $connector,
        string $pathSlug = 'webhooks/test-partner',
        bool $active = true,
    ): WebhookEndpoint {
        $endpoint = new WebhookEndpoint([
            'tenant_id' => $connector->tenant_id,
            'connector_id' => $connector->id,
            'path_slug' => $pathSlug,
            'active' => $active,
        ]);
        $endpoint->save();

        return $endpoint;
    }
}
