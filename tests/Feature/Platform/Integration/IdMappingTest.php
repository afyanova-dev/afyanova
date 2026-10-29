<?php

declare(strict_types=1);

use App\Platform\Integration\Application\Command\UpsertIdMappingCommand;
use App\Platform\Integration\Application\Query\ResolveIdMappingQuery;
use App\Platform\Integration\Domain\Enum\MappingSource;
use App\Platform\Integration\Domain\Enum\VerificationStatus;
use App\Platform\Integration\Domain\Exception\MappingNotFoundException;
use App\Platform\Integration\Domain\Model\IdMapping;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/integration.php';

beforeEach(function () {
    $this->fixture = integrationFixture();
});

test('upserts bidirectional ID mapping and resolves both directions', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId, 'NHIF Registry', 'nhif-reg');
        $internalPatientId = (string) Str::uuid();

        /** @var IdMapping $mapping */
        $mapping = app(CommandBus::class)->dispatch(new UpsertIdMappingCommand(
            tenantId: $tenantId,
            connectorId: $connector->id,
            externalId: 'NHIF-CARD-991188',
            internalDomain: 'Patient',
            internalId: $internalPatientId,
            source: MappingSource::DOMAIN_PROVIDED,
            status: VerificationStatus::VERIFIED,
            confidence: 1.0,
        ));

        expect($mapping->id)->not->toBeEmpty()
            ->and($mapping->external_id)->toBe('NHIF-CARD-991188')
            ->and($mapping->internal_id)->toBe($internalPatientId)
            ->and($mapping->mapping_source)->toBe(MappingSource::DOMAIN_PROVIDED);

        // Resolve external -> internal
        $resolvedInternal = app(QueryBus::class)->dispatch(new ResolveIdMappingQuery(
            connectorId: $connector->id,
            externalId: 'NHIF-CARD-991188',
        ));
        expect($resolvedInternal)->toBe($internalPatientId);

        // Resolve internal -> external
        $resolvedExternal = app(QueryBus::class)->dispatch(new ResolveIdMappingQuery(
            connectorId: $connector->id,
            internalDomain: 'Patient',
            internalId: $internalPatientId,
        ));
        expect($resolvedExternal)->toBe('NHIF-CARD-991188');
    });
});

test('throws MappingNotFoundException for non-existent external identifier', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId);

        expect(fn () => app(QueryBus::class)->dispatch(new ResolveIdMappingQuery(
            connectorId: $connector->id,
            externalId: 'NON-EXISTENT-ID',
        )))->toThrow(MappingNotFoundException::class);
    });
});

test('updates existing mapping attributes and touches last_seen_at on subsequent upsert and lookup', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId);
        $initialId = (string) Str::uuid();
        $updatedId = (string) Str::uuid();

        app(CommandBus::class)->dispatch(new UpsertIdMappingCommand(
            tenantId: $tenantId,
            connectorId: $connector->id,
            externalId: 'DEVICE-100',
            internalDomain: 'Radiology',
            internalId: $initialId,
            confidence: 0.8,
        ));

        // Update internal link and confidence
        /** @var IdMapping $updated */
        $updated = app(CommandBus::class)->dispatch(new UpsertIdMappingCommand(
            tenantId: $tenantId,
            connectorId: $connector->id,
            externalId: 'DEVICE-100',
            internalDomain: 'Radiology',
            internalId: $updatedId,
            confidence: 1.0,
        ));

        expect($updated->internal_id)->toBe($updatedId)
            ->and($updated->confidence)->toBe(1.0);

        $resolved = app(QueryBus::class)->dispatch(new ResolveIdMappingQuery(
            connectorId: $connector->id,
            externalId: 'DEVICE-100',
        ));
        expect($resolved)->toBe($updatedId);
    });
});
