<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounterResult;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Encounter\Contracts\Event\EncounterCreated;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Exception\ActiveEncounterExists;
use App\Domains\Encounter\Domain\Exception\EncounterTypeNotAvailable;
use App\Domains\Encounter\Domain\Exception\PatientNotEligibleForEncounter;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Concurrency\ConcurrencyConflict;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 (increment E.1) gate — CreateEncounter end-to-end through the real
 | CommandBus pipeline (IC §5): authorize -> claim idempotency -> handle ->
 | write audit -> write outbox, one transaction, inside Tenancy::run
 | (11-encounter-domain.md §8, §101; ADR-101, ADR-104, ADR-115, ADR-120,
 | ADR-121, ADR-124, ADR-130). Backend only — HTTP routes are PHASE 16.
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $capabilities
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function encounterFixture(
    array $permissions = ['encounter.encounters.create', 'patient.patients.read'],
    array $capabilities = ['OUTPATIENT_CARE'],
): array {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser($permissions, $capabilities);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function seedEncounterPatient(string $tenantId, array $attributes = []): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)->create(array_merge(['sex' => Sex::Female], $attributes))->id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function dispatchCreateEncounter(string $tenantId, ?string $facilityId, array $overrides): CreateEncounterResult
{
    $command = new CreateEncounter(
        encounterId: $overrides['encounterId'] ?? (string) Str::uuid7(),
        patientId: $overrides['patientId'],
        actorId: $overrides['actorId'],
        encounterType: $overrides['encounterType'] ?? EncounterType::Outpatient,
        planned: $overrides['planned'] ?? false,
        departmentId: $overrides['departmentId'] ?? null,
        unitId: $overrides['unitId'] ?? null,
        source: $overrides['source'] ?? null,
        idempotencyKey: $overrides['idempotencyKey'] ?? null,
    );

    /** @var CreateEncounterResult */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

// ── happy path ───────────────────────────────────────────────────────

test('it creates an ARRIVED encounter: aggregate persisted, number allocated, event on the outbox, audit written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id);

    $result = dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId,
        'actorId' => (string) $user->id,
    ]);

    expect($result->encounterNumber)->toBe('ENC-00000001');

    $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($result->encounterId));
    expect($encounter)->not->toBeNull()
        ->and($encounter->encounter_number)->toBe('ENC-00000001')
        ->and($encounter->status)->toBe(EncounterStatus::Arrived)
        ->and($encounter->encounter_type)->toBe(EncounterType::Outpatient)
        ->and($encounter->disposition)->toBeNull()
        ->and((string) $encounter->tenant_id)->toBe((string) $tenant->id)
        ->and((string) $encounter->facility_id)->toBe((string) $facility->id)
        ->and((string) $encounter->patient_id)->toBe($patientId)
        ->and((string) $encounter->created_by_user_id)->toBe((string) $user->id)
        ->and($encounter->created_source)->toBe('RECEPTION')
        ->and($encounter->arrived_at)->not->toBeNull()
        ->and((int) $encounter->version)->toBe(0);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterCreated::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterCreated')
        ->and($outbox->published_at)->toBeNull();
    $payload = json_decode((string) $outbox->payload, true);
    expect($payload['encounterId'])->toBe($result->encounterId)
        ->and($payload['patientId'])->toBe($patientId)
        ->and($payload['encounterType'])->toBe('OUTPATIENT')
        ->and($payload['status'])->toBe('ARRIVED')
        ->and((string) json_encode($payload))->not->toContain('RECEPTION');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'encounter.created')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('encounter')
        ->and($audit->target_id)->toBe($result->encounterId)
        ->and($audit->facility_id)->toBe((string) $facility->id);
    $meta = json_decode((string) $audit->metadata, true);
    expect($meta)->toEqual([
        'patient_id' => $patientId,
        'encounter_type' => 'OUTPATIENT',
        'source' => 'RECEPTION',
        'planned' => false,
    ]);
});

test('a planned intent produces a PLANNED encounter with no arrived_at', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id);

    $result = dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId, 'actorId' => (string) $user->id, 'planned' => true,
    ]);

    $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($result->encounterId));
    expect($encounter->status)->toBe(EncounterStatus::Planned)
        ->and($encounter->arrived_at)->toBeNull();
});

test('an optional department/unit and an explicit source are persisted', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id);
    $departmentId = (string) Str::uuid7();
    $unitId = (string) Str::uuid7();

    $result = dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId, 'actorId' => (string) $user->id,
        'departmentId' => $departmentId, 'unitId' => $unitId, 'source' => 'INTEGRATION',
    ]);

    $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($result->encounterId));
    expect((string) $encounter->department_id)->toBe($departmentId)
        ->and((string) $encounter->unit_id)->toBe($unitId)
        ->and($encounter->created_source)->toBe('INTEGRATION');
});

// ── encounter number (ADR-115) ───────────────────────────────────────

test('encounter numbers are ENC-%08d, sequential, and tenant-scoped', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();
    $p1 = seedEncounterPatient((string) $tenant->id);
    $p2 = seedEncounterPatient((string) $tenant->id);

    $first = dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, ['patientId' => $p1, 'actorId' => (string) $user->id]);
    $second = dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, ['patientId' => $p2, 'actorId' => (string) $user->id]);

    expect($first->encounterNumber)->toBe('ENC-00000001')
        ->and($second->encounterNumber)->toBe('ENC-00000002');

    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = encounterFixture();
    $pB = seedEncounterPatient((string) $tenantB->id);
    $inB = dispatchCreateEncounter((string) $tenantB->id, (string) $facilityB->id, ['patientId' => $pB, 'actorId' => (string) $userB->id]);
    expect($inB->encounterNumber)->toBe('ENC-00000001'); // a fresh tenant starts at 1

    $counter = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounter_number_counters')->where('tenant_id', $tenant->id)->first());
    expect((int) $counter->next_value)->toBe(3); // two allocations
});

// ── capability gating (ADR-120) ──────────────────────────────────────

test('every EncounterType creates when its capability is enabled (or it is not gated)', function () {
    $gated = ['OUTPATIENT_CARE', 'EMERGENCY_CARE', 'INPATIENT_CARE', 'MATERNITY'];
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture(capabilities: $gated);

    foreach (EncounterType::cases() as $type) {
        $patientId = seedEncounterPatient((string) $tenant->id);
        $result = dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
            'patientId' => $patientId, 'actorId' => (string) $user->id, 'encounterType' => $type,
        ]);
        $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($result->encounterId));
        expect($encounter->encounter_type)->toBe($type);
    }
});

test('a capability-gated type is rejected when the facility capability is not ENABLED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture(capabilities: ['OUTPATIENT_CARE']); // no EMERGENCY_CARE
    $patientId = seedEncounterPatient((string) $tenant->id);

    expect(fn () => dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId, 'actorId' => (string) $user->id, 'encounterType' => EncounterType::Emergency,
    ]))->toThrow(EncounterTypeNotAvailable::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->count()))->toBe(0);
});

test('an ungated type (DIAGNOSTIC) creates with no capability enabled at all', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture(capabilities: []);
    $patientId = seedEncounterPatient((string) $tenant->id);

    $result = dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId, 'actorId' => (string) $user->id, 'encounterType' => EncounterType::Diagnostic,
    ]);
    $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($result->encounterId));
    expect($encounter->encounter_type)->toBe(EncounterType::Diagnostic);
});

// ── patient validation via PatientApi (ADR-130 E) ────────────────────

test('a missing / cross-tenant patient is rejected with NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();

    expect(fn () => dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => (string) Str::uuid7(), 'actorId' => (string) $user->id,
    ]))->toThrow(NotFound::class);
});

test('a MERGED patient is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id, ['status' => PatientStatus::Merged]);

    expect(fn () => dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId, 'actorId' => (string) $user->id,
    ]))->toThrow(PatientNotEligibleForEncounter::class);
});

test('a DECEASED patient is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id, ['status' => PatientStatus::Deceased]);

    expect(fn () => dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId, 'actorId' => (string) $user->id,
    ]))->toThrow(PatientNotEligibleForEncounter::class);
});

test('an INACTIVE patient is rejected (reactivate first — ADR-116)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id, ['status' => PatientStatus::Inactive]);

    expect(fn () => dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId, 'actorId' => (string) $user->id,
    ]))->toThrow(PatientNotEligibleForEncounter::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->count()))->toBe(0);
});

test('a provisional (ACTIVE) patient is permitted — the ADR-119 emergency flow', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture(capabilities: ['EMERGENCY_CARE']);
    $patientId = seedEncounterPatient((string) $tenant->id, [
        'is_provisional' => true, 'sex' => Sex::Unknown, 'date_of_birth' => null,
    ]);

    $result = dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId, 'actorId' => (string) $user->id, 'encounterType' => EncounterType::Emergency, 'source' => 'EMERGENCY',
    ]);

    $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($result->encounterId));
    expect($encounter->status)->toBe(EncounterStatus::Arrived);

    // the encounter does not touch is_provisional
    $patient = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Patient::query()->find($patientId));
    expect($patient->is_provisional)->toBeTrue();
});

test('the caller needs patient.patients.read for the PatientApi lookup', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        encounterFixture(permissions: ['encounter.encounters.create']); // no patient.patients.read
    $patientId = seedEncounterPatient((string) $tenant->id);

    expect(fn () => dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId, 'actorId' => (string) $user->id,
    ]))->toThrow(AuthorizationException::class);
});

// ── authorization ────────────────────────────────────────────────────

test('it denies a caller without encounter.encounters.create (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        encounterFixture(permissions: ['patient.patients.read']);
    $patientId = seedEncounterPatient((string) $tenant->id);

    expect(fn () => dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId, 'actorId' => (string) $user->id,
    ]))->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->count()))->toBe(0);
});

test('it denies a caller acting with no facility context (an encounter needs a facility)', function () {
    ['tenant' => $tenant, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id);

    expect(fn () => dispatchCreateEncounter((string) $tenant->id, null, [
        'patientId' => $patientId, 'actorId' => (string) $user->id,
    ]))->toThrow(AuthorizationException::class);
});

// ── tenant isolation / facility scoping ──────────────────────────────

test('an encounter created under tenant A is invisible under tenant B (application layer)', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenantA->id);
    $result = dispatchCreateEncounter((string) $tenantA->id, (string) $facilityA->id, ['patientId' => $patientId, 'actorId' => (string) $userA->id]);

    ['tenant' => $tenantB, 'facility' => $facilityB] = encounterFixture();

    $underA = TenantContextScope::run((string) $tenantA->id, (string) $facilityA->id, fn () => Encounter::query()->find($result->encounterId));
    $underB = TenantContextScope::run((string) $tenantB->id, (string) $facilityB->id, fn () => Encounter::query()->find($result->encounterId));

    expect($underA)->not->toBeNull()->and($underB)->toBeNull();
});

test('the encounter records the actor\'s active facility, never a client-supplied one', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id);

    // CreateEncounter has no tenant_id / facility_id parameter — the command cannot carry one.
    $result = dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, ['patientId' => $patientId, 'actorId' => (string) $user->id]);

    $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($result->encounterId));
    expect((string) $encounter->facility_id)->toBe((string) $facility->id);
});

// ── active-attendance uniqueness (ADR-121, Invariant 12b) ────────────

test('a second active encounter for the same patient at the same facility is rejected by the DB index', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id);

    dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, ['patientId' => $patientId, 'actorId' => (string) $user->id]);

    expect(fn () => dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId, 'actorId' => (string) $user->id,
    ]))->toThrow(ActiveEncounterExists::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('patient_id', $patientId)->count()))->toBe(1);
});

test('the same patient MAY have a concurrent active encounter at a DIFFERENT facility', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facilityA, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id);

    $facilityBId = (string) TenantContextScope::run((string) $tenant->id, null, fn () => Facility::factory()
        ->forTenant((string) $tenant->id, (string) $org->id)->create()->id);
    TenantContextScope::run((string) $tenant->id, null, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => $facilityBId,
        'capability_code' => 'OUTPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));
    // grant the user a facility-scoped role at facility B too
    ['role_id' => $roleId] = makeTenantWithUser([]); // throwaway; we reuse the user's own role instead
    TenantContextScope::run((string) $tenant->id, null, fn () => DB::table('idn_access_assignments')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'user_id' => $user->id,
        'role_id' => DB::table('idn_access_assignments')->where('user_id', $user->id)->value('role_id'),
        'facility_id' => $facilityBId, 'status' => 'ACTIVE', 'is_primary' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $a = dispatchCreateEncounter((string) $tenant->id, (string) $facilityA->id, ['patientId' => $patientId, 'actorId' => (string) $user->id]);
    $b = dispatchCreateEncounter((string) $tenant->id, $facilityBId, ['patientId' => $patientId, 'actorId' => (string) $user->id]);

    expect($a->encounterId)->not->toBe($b->encounterId);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('patient_id', $patientId)->count()))->toBe(2);
});

test('two concurrent CreateEncounter for the same patient/facility — the DB index yields exactly one winner', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id);

    // simulate the race: a committed encounter from a "parallel" transaction, then our dispatch
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::factory()
        ->forTenant((string) $tenant->id)->forPatient($patientId)->atFacility((string) $facility->id)
        ->withStatus(EncounterStatus::Active)->create(['encounter_number' => 'ENC-09999999']));

    expect(fn () => dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId, 'actorId' => (string) $user->id,
    ]))->toThrow(ActiveEncounterExists::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('patient_id', $patientId)->count()))->toBe(1);
});

// ── idempotency + optimistic version ─────────────────────────────────

test('a repeated idempotencyKey does not create a second encounter and replays the same result', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id);
    $key = (string) Str::uuid7();
    $encounterId = (string) Str::uuid7();

    $first = dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'encounterId' => $encounterId, 'patientId' => $patientId, 'actorId' => (string) $user->id, 'idempotencyKey' => $key,
    ]);
    $second = dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, [
        'encounterId' => $encounterId, 'patientId' => $patientId, 'actorId' => (string) $user->id, 'idempotencyKey' => $key,
    ]);

    expect($second->encounterId)->toBe($first->encounterId)
        ->and($second->encounterNumber)->toBe($first->encounterNumber);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->count()))->toBe(1);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.created')->count()))->toBe(1);
});

test('a stale in-memory Encounter save throws ConcurrencyConflict (optimistic lock intact)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id);
    $result = dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, ['patientId' => $patientId, 'actorId' => (string) $user->id]);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($result) {
        $stale = Encounter::query()->findOrFail($result->encounterId); // version 0
        Encounter::query()->whereKey($result->encounterId)->update(['version' => 1, 'created_source' => 'X']); // bump out of band

        $stale->created_source = 'Y';
        $stale->save();
    });
})->throws(ConcurrencyConflict::class);

// ── EncounterApi ─────────────────────────────────────────────────────

test('EncounterApi::encounterExists is true for a tenant encounter and false for a missing / cross-tenant one', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterFixture();
    $patientId = seedEncounterPatient((string) $tenant->id);
    $result = dispatchCreateEncounter((string) $tenant->id, (string) $facility->id, ['patientId' => $patientId, 'actorId' => (string) $user->id]);

    $existsForOwn = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(EncounterApi::class)->encounterExists($result->encounterId));
    expect($existsForOwn)->toBeTrue();

    ['tenant' => $tenantB, 'facility' => $facilityB] = encounterFixture();
    $existsForOther = TenantContextScope::run((string) $tenantB->id, (string) $facilityB->id, fn () => app(EncounterApi::class)->encounterExists($result->encounterId));
    expect($existsForOther)->toBeFalse();

    $existsForBogus = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(EncounterApi::class)->encounterExists((string) Str::uuid7()));
    expect($existsForBogus)->toBeFalse();
});

// ── validation ───────────────────────────────────────────────────────

test('CreateEncounter rejects malformed ids at construction (IC §3)', function () {
    expect(fn () => new CreateEncounter('nope', (string) Str::uuid7(), (string) Str::uuid7(), EncounterType::Outpatient))
        ->toThrow(InvalidArgumentException::class);
});
