<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\RecordArrival;
use App\Domains\Encounter\Contracts\Event\EncounterArrived;
use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Exception\ActiveEncounterExists;
use App\Domains\Encounter\Domain\Exception\IllegalEncounterTransition;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Organization\Domain\Model\Facility;
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
 | PHASE 5 (increment E.2) gate — RecordArrival (PLANNED -> ARRIVED) end-to-end
 | through the real CommandBus pipeline (IC §5): authorize (per-subject) ->
 | lifecycle guard -> aggregate transition -> versioned save -> outbox + audit,
 | one transaction (11-encounter-domain.md §19, §101; ADR-121, ADR-124, ADR-130).
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @param  list<string>  $capabilities
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function arrivalFixture(array $permissions = ['encounter.encounters.update'], array $capabilities = []): array
{
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser($permissions, $capabilities);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedPlannedEncounter(string $tenantId, string $facilityId, array $overrides = []): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient($overrides['patientId'] ?? (string) Str::uuid7())
        ->planned()
        ->create($overrides['attributes'] ?? [])->id);
}

function dispatchRecordArrival(string $tenantId, ?string $facilityId, string $encounterId, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new RecordArrival($encounterId, $actorId)));
}

// ── happy path ───────────────────────────────────────────────────────

test('PLANNED -> ARRIVED: status + arrived_at set, EncounterArrived on the outbox, audit written, version bumped', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = arrivalFixture();
    $patientId = (string) Str::uuid7();
    $encounterId = seedPlannedEncounter((string) $tenant->id, (string) $facility->id, ['patientId' => $patientId]);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($before->status)->toBe('PLANNED')->and($before->arrived_at)->toBeNull();

    dispatchRecordArrival((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ARRIVED')
        ->and($row->arrived_at)->not->toBeNull()
        ->and((int) $row->version)->toBe(1)
        // immutable / untouched
        ->and($row->encounter_type)->toBe($before->encounter_type)
        ->and($row->patient_id)->toBe($before->patient_id)
        ->and($row->tenant_id)->toBe($before->tenant_id)
        ->and($row->facility_id)->toBe($before->facility_id)
        ->and($row->encounter_number)->toBe($before->encounter_number)
        ->and($row->department_id)->toBe($before->department_id)
        ->and($row->unit_id)->toBe($before->unit_id)
        ->and($row->disposition)->toBe($before->disposition)
        ->and($row->created_at)->toBe($before->created_at)
        ->and($row->created_by_user_id)->toBe($before->created_by_user_id)
        ->and($row->created_source)->toBe($before->created_source)
        // other lifecycle timestamps untouched
        ->and($row->started_at)->toBeNull()
        ->and($row->admitted_at)->toBeNull()
        ->and($row->completed_at)->toBeNull();

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterArrived::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterArrived')
        ->and($outbox->published_at)->toBeNull();
    $payload = json_decode((string) $outbox->payload, true);
    expect($payload)->toHaveKeys(['eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId', 'occurredAt', 'correlationId', 'causationId'])
        ->and($payload['encounterId'])->toBe($encounterId)
        ->and($payload['patientId'])->toBe($patientId)
        ->and($payload['facilityId'])->toBe((string) $facility->id);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.arrived')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('encounter')
        ->and($audit->target_id)->toBe($encounterId)
        ->and($audit->facility_id)->toBe((string) $facility->id)
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('RecordArrival composes with a CreateEncounter(planned) — the two commands together check a scheduled patient in', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = arrivalFixture(
        ['encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.read'],
        ['OUTPATIENT_CARE'],
    );

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Female])->id);

    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient, planned: true),
    ));

    dispatchRecordArrival((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($encounterId));
    expect($encounter->status)->toBe(EncounterStatus::Arrived)
        ->and($encounter->arrived_at)->not->toBeNull();
});

// ── invalid source states (ADR-121, Invariant 15) ────────────────────

test('every source status except PLANNED is rejected with IllegalEncounterTransition (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = arrivalFixture();

    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Planned) {
            continue;
        }

        $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
            ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)
            ->forPatient((string) Str::uuid7())->withStatus($status)->create()->id);

        expect(fn () => dispatchRecordArrival((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
            ->toThrow(IllegalEncounterTransition::class);

        // no state change, no event, no audit
        $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
        expect($row->status)->toBe($status->value)
            ->and((int) $row->version)->toBe(0);
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterArrived::class)->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.arrived')->count()))->toBe(0);
});

test('an already-ARRIVED encounter is rejected — RecordArrival is not idempotent', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = arrivalFixture();
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)
        ->forPatient((string) Str::uuid7())->withStatus(EncounterStatus::Arrived)->create()->id);

    $arrivedAtBefore = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('arrived_at'));

    expect(fn () => dispatchRecordArrival((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(IllegalEncounterTransition::class);

    // arrived_at is not re-stamped
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('arrived_at')))
        ->toBe($arrivedAtBefore);
});

// ── active-attendance uniqueness (ADR-121, Invariant 12b) ────────────

test('arriving a PLANNED encounter when the patient already has an active one at that facility raises ActiveEncounterExists', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = arrivalFixture();
    $patientId = (string) Str::uuid7();

    // an existing ARRIVED encounter for the same patient + facility (PLANNED does not participate in the index)
    TenantContextScope::run((string) $tenant->id, null, fn () => Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)
        ->forPatient($patientId)->withStatus(EncounterStatus::Arrived)->create());

    $plannedId = seedPlannedEncounter((string) $tenant->id, (string) $facility->id, ['patientId' => $patientId]);

    expect(fn () => dispatchRecordArrival((string) $tenant->id, (string) $facility->id, $plannedId, (string) $user->id))
        ->toThrow(ActiveEncounterExists::class);

    // the PLANNED encounter is untouched
    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $plannedId)->first());
    expect($row->status)->toBe('PLANNED')
        ->and($row->arrived_at)->toBeNull()
        ->and((int) $row->version)->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.arrived')->count()))->toBe(0);
});

test('the same patient MAY arrive a PLANNED encounter at a facility where they have no active encounter', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facilityA, 'user' => $user] =
        arrivalFixture(['encounter.encounters.update']);
    $patientId = (string) Str::uuid7();

    // active at facility A
    TenantContextScope::run((string) $tenant->id, null, fn () => Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facilityA->id)
        ->forPatient($patientId)->withStatus(EncounterStatus::Active)->create());

    // PLANNED at facility B; grant the user a role there too
    $facilityBId = (string) TenantContextScope::run((string) $tenant->id, null, fn () => Facility::factory()
        ->forTenant((string) $tenant->id, (string) $org->id)->create()->id);
    TenantContextScope::run((string) $tenant->id, null, fn () => DB::table('idn_access_assignments')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'user_id' => $user->id,
        'role_id' => DB::table('idn_access_assignments')->where('user_id', $user->id)->value('role_id'),
        'facility_id' => $facilityBId, 'status' => 'ACTIVE', 'is_primary' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $plannedId = seedPlannedEncounter((string) $tenant->id, $facilityBId, ['patientId' => $patientId]);

    dispatchRecordArrival((string) $tenant->id, $facilityBId, $plannedId, (string) $user->id);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $plannedId)->value('status')))->toBe('ARRIVED');
});

// ── authorization ────────────────────────────────────────────────────

test('it denies a caller without encounter.encounters.update (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = arrivalFixture([]); // no keys
    $encounterId = seedPlannedEncounter((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchRecordArrival((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('PLANNED');
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.arrived')->count()))->toBe(0);
});

test('per-subject facility scope: a caller not assigned at the encounter facility is denied (Invariant 24/25)', function () {
    ['tenant' => $tenant, 'user' => $user] = arrivalFixture(['encounter.encounters.update']);
    // encounter at a facility the user has NO assignment for
    $encounterId = seedPlannedEncounter((string) $tenant->id, (string) Str::uuid7());

    expect(fn () => dispatchRecordArrival((string) $tenant->id, null, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('PLANNED');
});

// ── isolation / not found ────────────────────────────────────────────

test('a missing / cross-tenant encounter is rejected with NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = arrivalFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = arrivalFixture();

    $encounterId = seedPlannedEncounter((string) $tenantA->id, (string) $facilityA->id);

    // missing
    expect(fn () => dispatchRecordArrival((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7(), (string) $userA->id))
        ->toThrow(NotFound::class);

    // cross-tenant — tenant B cannot see tenant A's encounter (RLS)
    expect(fn () => dispatchRecordArrival((string) $tenantB->id, (string) $facilityB->id, $encounterId, (string) $userB->id))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenantA->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('PLANNED');
});

// ── optimistic concurrency (IC §9.1, §144, §145) ────────────────────

test('a stale in-memory recordArrival() save throws ConcurrencyConflict', function () {
    ['tenant' => $tenant, 'facility' => $facility] = arrivalFixture();
    $encounterId = seedPlannedEncounter((string) $tenant->id, (string) $facility->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId) {
        $stale = Encounter::query()->findOrFail($encounterId); // version 0
        Encounter::query()->whereKey($encounterId)->update(['version' => 1]); // another writer bumps it

        $stale->recordArrival();
        app(EncounterRepository::class)->save($stale);
    });
})->throws(ConcurrencyConflict::class);

// ── validation ───────────────────────────────────────────────────────

test('RecordArrival rejects malformed ids at construction (IC §3)', function () {
    expect(fn () => new RecordArrival('nope', (string) Str::uuid7()))->toThrow(InvalidArgumentException::class);
});
