<?php

declare(strict_types=1);

use App\Platform\Integration\Application\Command\ConfigureWebhookEndpointCommand;
use App\Platform\Integration\Application\Command\RegisterConnectorCommand;
use App\Platform\Integration\Application\Command\RotateCredentialCommand;
use App\Platform\Integration\Application\Command\UpdateConnectorStatusCommand;
use App\Platform\Integration\Application\Query\GetConnectorQuery;
use App\Platform\Integration\Application\Query\ListConnectorsQuery;
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
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;

require_once __DIR__.'/../../../../tests/Support/integration.php';

beforeEach(function () {
    $this->fixture = integrationFixture();
});

test('registers a new connector in active status', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $command = new RegisterConnectorCommand(
            tenantId: $tenantId,
            name: 'National Health Lab System',
            key: 'nhls-lab',
            family: ConnectorFamily::DIAGNOSTIC_ANALYZER,
            direction: ConnectorDirection::BIDIRECTIONAL,
            baseUrl: 'https://lis.example.com/api/v1',
            authType: AuthType::HMAC_SHA256,
            payloadSensitivity: PayloadSensitivity::STANDARD,
            config: ['timeout' => 30],
        );

        /** @var IntegrationConnector $connector */
        $connector = app(CommandBus::class)->dispatch($command);

        expect($connector)->toBeInstanceOf(IntegrationConnector::class)
            ->and($connector->id)->not->toBeEmpty()
            ->and($connector->tenant_id)->toBe($tenantId)
            ->and($connector->name)->toBe('National Health Lab System')
            ->and($connector->key)->toBe('nhls-lab')
            ->and($connector->family)->toBe(ConnectorFamily::DIAGNOSTIC_ANALYZER)
            ->and($connector->status)->toBe(ConnectorStatus::ENABLED);

        // Query back
        /** @var IntegrationConnector|null $found */
        $found = app(QueryBus::class)->dispatch(new GetConnectorQuery($connector->id));
        expect($found)->not->toBeNull()
            ->and($found->id)->toBe($connector->id);

        $list = app(QueryBus::class)->dispatch(new ListConnectorsQuery);
        expect($list)->toHaveCount(1);
    });
});

test('updates connector status to disabled or maintenance', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId);

        app(CommandBus::class)->dispatch(new UpdateConnectorStatusCommand(
            connectorId: $connector->id,
            status: ConnectorStatus::DISABLED,
        ));

        $refreshed = app(QueryBus::class)->dispatch(new GetConnectorQuery($connector->id));
        expect($refreshed->status)->toBe(ConnectorStatus::DISABLED);
    });
});

test('rotates connector credential transitioning previous to rotating', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId);
        $initialCred = createTestCredential($connector, 'secret-v1');

        expect($initialCred->status)->toBe(CredentialStatus::ACTIVE);

        /** @var IntegrationCredential $newCred */
        $newCred = app(CommandBus::class)->dispatch(new RotateCredentialCommand(
            connectorId: $connector->id,
            algorithm: CredentialAlgorithm::HMAC_SHA256,
            secretRef: 'secret-v2',
        ));

        expect($newCred->id)->not->toBe($initialCred->id)
            ->and($newCred->status)->toBe(CredentialStatus::ACTIVE)
            ->and($newCred->secret_ref)->toBe('secret-v2');

        $initialCred->refresh();
        expect($initialCred->status)->toBe(CredentialStatus::ROTATING);
    });
});

test('configures webhook endpoint for connector', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId);

        /** @var WebhookEndpoint $endpoint */
        $endpoint = app(CommandBus::class)->dispatch(new ConfigureWebhookEndpointCommand(
            connectorId: $connector->id,
            pathSlug: 'api/webhooks/lab-results',
            secretRef: 'endpoint-secret',
            allowedIps: ['10.0.0.1', '10.0.0.2'],
            active: true,
        ));

        expect($endpoint->id)->not->toBeEmpty()
            ->and($endpoint->path_slug)->toBe('api/webhooks/lab-results')
            ->and($endpoint->connector_id)->toBe($connector->id)
            ->and($endpoint->tenant_id)->toBe($tenantId)
            ->and($endpoint->allowed_ips)->toBe(['10.0.0.1', '10.0.0.2'])
            ->and($endpoint->active)->toBeTrue();
    });
});
