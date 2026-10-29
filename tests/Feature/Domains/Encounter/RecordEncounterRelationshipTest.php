<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\RecordEncounterRelationship;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Encounter\Domain\Model\EncounterRelationship;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
 | ADR-151 D-C gate — RecordEncounterRelationship (11-encounter-domain.md
 | §172) + the EncounterApi::recordEncounterRelationship write method Care
 | Coordination's LinkReferralEncounterHandler will call. Plain append-only
 | child row, no domain event (the Room precedent).
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @return array{tenant: mixed, facility: mixed, user: mixed}
 */
function encounterRelationshipFixture(array $permissions = ['encounter.encounters.link_relationship']): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser($permissions, []);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'facility' => $facility, 'user' => $user];
}

function seedEncounterForRelationship(string $tenantId, string $facilityId): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient((string) Str::uuid7())
        ->ofType(EncounterType::Outpatient)
        ->withStatus(EncounterStatus::Active)
        ->create()->id);
}

test('RecordEncounterRelationship writes an enc_encounter_relationships row, no event, no version bump on the encounter', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterRelationshipFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $encounterId = seedEncounterForRelationship($tenantId, $facilityId);
    $relatedEncounterId = seedEncounterForRelationship($tenantId, $facilityId);
    $relationshipId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new RecordEncounterRelationship(
        relationshipId: $relationshipId,
        encounterId: $encounterId,
        relatedEncounterId: $relatedEncounterId,
        relationshipType: 'referred_from',
        actorId: (string) $user->id,
    )));

    $row = TenantContextScope::run($tenantId, $facilityId, fn () => EncounterRelationship::query()->find($relationshipId));

    expect($row)->not->toBeNull()
        ->and((string) $row->encounter_id)->toBe($encounterId)
        ->and((string) $row->related_encounter_id)->toBe($relatedEncounterId)
        ->and($row->relationship_type)->toBe('REFERRED_FROM')
        ->and((string) $row->created_by_user_id)->toBe((string) $user->id)
        ->and((int) $row->version)->toBe(0); // shape/conformance-only column (ADR-151 Governance note)

    $encounter = TenantContextScope::run($tenantId, $facilityId, fn () => Encounter::query()->find($encounterId));
    expect((int) $encounter->version)->toBe(0);
});

test('RecordEncounterRelationship is denied without encounter.encounters.link_relationship', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterRelationshipFixture([]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $encounterId = seedEncounterForRelationship($tenantId, $facilityId);

    $command = new RecordEncounterRelationship(
        relationshipId: (string) Str::uuid7(),
        encounterId: $encounterId,
        relatedEncounterId: (string) Str::uuid7(),
        relationshipType: 'RELATED_TO',
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(AuthorizationException::class);
});

test('EncounterApi::recordEncounterRelationship dispatches the command end to end', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterRelationshipFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $encounterId = seedEncounterForRelationship($tenantId, $facilityId);
    $relatedEncounterId = seedEncounterForRelationship($tenantId, $facilityId);
    $relationshipId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, $facilityId, fn () => app(EncounterApi::class)->recordEncounterRelationship(
        relationshipId: $relationshipId,
        encounterId: $encounterId,
        relatedEncounterId: $relatedEncounterId,
        relationshipType: 'REFERRED_FROM',
        actorId: (string) $user->id,
    ));

    $row = TenantContextScope::run($tenantId, $facilityId, fn () => EncounterRelationship::query()->find($relationshipId));
    expect($row)->not->toBeNull();
});
