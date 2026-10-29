<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\AdmitEncounter;
use App\Domains\Encounter\Application\Command\AssignEncounterLocation;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Contracts\Event\EncounterLocationChanged;
use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Exception\IllegalEncounterTransition;
use App\Domains\Encounter\Domain\Model\Encounter;
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
 | PHASE 5 (increment E.6) gate — AssignEncounterLocation (set / change the coarse
 | care-context department_id / unit_id on an ARRIVED or ACTIVE encounter, status
 | unchanged) end-to-end through the real CommandBus pipeline (IC §5): authorize
 | (per-subject) -> lifecycle guard (status) -> aggregate patch + no-op guard ->
 | versioned save -> outbox + audit, one transaction (11-encounter-domain.md §36,
 | §37, §38, §131, §163; ADR-107, ADR-121, ADR-130 B). NOT a bed / room / ward
 | assignment (Bed & Ward Management) and NOT operational location (Queueing).
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function locationFixture(array $permissions = ['encounter.encounters.assign_location']): array
{
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser($permissions, []);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedEncounterForLocation(string $tenantId, string $facilityId, EncounterStatus $status = EncounterStatus::Active, array $overrides = []): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient($overrides['patientId'] ?? (string) Str::uuid7())
        ->ofType($overrides['type'] ?? EncounterType::Outpatient)
        ->withStatus($status)
        ->create($overrides['attributes'] ?? [])->id);
}

function dispatchAssignLocation(string $tenantId, ?string $facilityId, string $encounterId, ?string $departmentId, ?string $unitId, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new AssignEncounterLocation($encounterId, $actorId, $departmentId, $unitId)));
}

// ── happy path ───────────────────────────────────────────────────────

test('ARRIVED / ACTIVE encounter: department and/or unit set, status unchanged, EncounterLocationChanged on the outbox, audit written (metadata []), version bumped', function (EncounterStatus $status, bool $withDept, bool $withUnit) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = locationFixture();
    $patientId = (string) Str::uuid7();
    $encounterId = seedEncounterForLocation((string) $tenant->id, (string) $facility->id, $status, ['patientId' => $patientId]);

    $departmentId = $withDept ? (string) Str::uuid7() : null;
    $unitId = $withUnit ? (string) Str::uuid7() : null;

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($before->status)->toBe($status->value)
        ->and($before->department_id)->toBeNull()
        ->and($before->unit_id)->toBeNull();

    dispatchAssignLocation((string) $tenant->id, (string) $facility->id, $encounterId, $departmentId, $unitId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe($status->value)                 // unchanged
        ->and($row->department_id)->toBe($departmentId)
        ->and($row->unit_id)->toBe($unitId)
        ->and((int) $row->version)->toBe(1)
        // type / disposition / transition timestamps / identity untouched
        ->and($row->encounter_type)->toBe($before->encounter_type)
        ->and($row->disposition)->toBe($before->disposition)
        ->and($row->arrived_at)->toBe($before->arrived_at)
        ->and($row->started_at)->toBe($before->started_at)
        ->and($row->admitted_at)->toBe($before->admitted_at)
        ->and($row->completed_at)->toBe($before->completed_at)
        ->and($row->patient_id)->toBe($before->patient_id)
        ->and($row->encounter_number)->toBe($before->encounter_number)
        ->and($row->created_at)->toBe($before->created_at)
        ->and($row->created_by_user_id)->toBe($before->created_by_user_id);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterLocationChanged::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterLocationChanged')
        ->and($outbox->published_at)->toBeNull();
    $payload = json_decode((string) $outbox->payload, true);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId',
        'departmentId', 'unitId', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['encounterId'])->toBe($encounterId)
        ->and($payload['patientId'])->toBe($patientId)
        ->and($payload['facilityId'])->toBe((string) $facility->id)
        ->and($payload['departmentId'])->toBe($departmentId)
        ->and($payload['unitId'])->toBe($unitId);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.location_changed')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('encounter')
        ->and($audit->target_id)->toBe($encounterId)
        ->and($audit->facility_id)->toBe((string) $facility->id)
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
})->with([
    'ARRIVED + department only' => [EncounterStatus::Arrived, true, false],
    'ARRIVED + unit only' => [EncounterStatus::Arrived, false, true],
    'ACTIVE + department only' => [EncounterStatus::Active, true, false],
    'ACTIVE + unit only' => [EncounterStatus::Active, false, true],
    'ACTIVE + both' => [EncounterStatus::Active, true, true],
]);

test('it changes an already-assigned location (the move case) — one more event, version bumps again', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = locationFixture();
    $dept1 = (string) Str::uuid7();
    $unit1 = (string) Str::uuid7();
    $encounterId = seedEncounterForLocation((string) $tenant->id, (string) $facility->id, EncounterStatus::Active, [
        'attributes' => ['department_id' => $dept1, 'unit_id' => $unit1],
    ]);

    $dept2 = (string) Str::uuid7();
    dispatchAssignLocation((string) $tenant->id, (string) $facility->id, $encounterId, $dept2, null, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->department_id)->toBe($dept2)
        ->and($row->unit_id)->toBe($unit1)   // patch — unit untouched
        ->and((int) $row->version)->toBe(1);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterLocationChanged::class)->count()))->toBe(1);
});

test('patch semantics — a null field leaves the stored value unchanged', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = locationFixture();
    $encounterId = seedEncounterForLocation((string) $tenant->id, (string) $facility->id);

    $dept = (string) Str::uuid7();
    $unit1 = (string) Str::uuid7();
    $unit2 = (string) Str::uuid7();

    dispatchAssignLocation((string) $tenant->id, (string) $facility->id, $encounterId, $dept, $unit1, (string) $user->id);
    dispatchAssignLocation((string) $tenant->id, (string) $facility->id, $encounterId, null, $unit2, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->department_id)->toBe($dept)   // preserved through the null patch
        ->and($row->unit_id)->toBe($unit2)
        ->and((int) $row->version)->toBe(2);
});

// ── resolved no-op ───────────────────────────────────────────────────

test('assigning the current location again is rejected (409) — no second event, version unchanged', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = locationFixture();
    $encounterId = seedEncounterForLocation((string) $tenant->id, (string) $facility->id);

    $dept = (string) Str::uuid7();
    $unit = (string) Str::uuid7();
    dispatchAssignLocation((string) $tenant->id, (string) $facility->id, $encounterId, $dept, $unit, (string) $user->id);

    expect(fn () => dispatchAssignLocation((string) $tenant->id, (string) $facility->id, $encounterId, $dept, $unit, (string) $user->id))
        ->toThrow(IllegalEncounterTransition::class);
    // a null patch that resolves to the same pair
    expect(fn () => dispatchAssignLocation((string) $tenant->id, (string) $facility->id, $encounterId, $dept, null, (string) $user->id))
        ->toThrow(IllegalEncounterTransition::class);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->department_id)->toBe($dept)->and($row->unit_id)->toBe($unit)->and((int) $row->version)->toBe(1);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterLocationChanged::class)->count()))->toBe(1);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.location_changed')->count()))->toBe(1);
});

// ── invalid source states ────────────────────────────────────────────

test('every status except ARRIVED / ACTIVE is rejected with IllegalEncounterTransition (409); no state / event / audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = locationFixture();

    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Arrived || $status === EncounterStatus::Active) {
            continue;
        }

        $encounterId = seedEncounterForLocation((string) $tenant->id, (string) $facility->id, $status);

        try {
            dispatchAssignLocation((string) $tenant->id, (string) $facility->id, $encounterId, (string) Str::uuid7(), null, (string) $user->id);
            test()->fail("expected IllegalEncounterTransition for source status {$status->value}");
        } catch (IllegalEncounterTransition $e) {
            expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
        }

        $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
        expect($row->status)->toBe($status->value)->and($row->department_id)->toBeNull()->and((int) $row->version)->toBe(0);
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterLocationChanged::class)->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.location_changed')->count()))->toBe(0);
});

// ── validation ───────────────────────────────────────────────────────

test('a both-null command is rejected at construction (ADR-130 B — nothing to assign)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = locationFixture();
    $encounterId = seedEncounterForLocation((string) $tenant->id, (string) $facility->id);

    expect(fn () => new AssignEncounterLocation($encounterId, (string) $user->id, null, null))
        ->toThrow(InvalidArgumentException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterLocationChanged::class)->count()))->toBe(0);
});

test('AssignEncounterLocation rejects malformed ids at construction (IC §3)', function () {
    $ok = (string) Str::uuid7();
    expect(fn () => new AssignEncounterLocation('nope', $ok, $ok, null))->toThrow(InvalidArgumentException::class);
    expect(fn () => new AssignEncounterLocation($ok, $ok, 'nope', null))->toThrow(InvalidArgumentException::class);
});

// ── authorization ────────────────────────────────────────────────────

test('it denies a caller without encounter.encounters.assign_location (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = locationFixture([]); // no keys
    $encounterId = seedEncounterForLocation((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchAssignLocation((string) $tenant->id, (string) $facility->id, $encounterId, (string) Str::uuid7(), null, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('department_id')))->toBeNull();
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.location_changed')->count()))->toBe(0);
});

test('the encounter.encounters.update key does NOT authorize a location assignment — it needs its own dedicated key', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = locationFixture(['encounter.encounters.update']);
    $encounterId = seedEncounterForLocation((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchAssignLocation((string) $tenant->id, (string) $facility->id, $encounterId, (string) Str::uuid7(), null, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('per-subject facility scope: a caller not assigned at the encounter facility is denied (Invariant 24/25)', function () {
    ['tenant' => $tenant, 'user' => $user] = locationFixture(['encounter.encounters.assign_location']);
    $encounterId = seedEncounterForLocation((string) $tenant->id, (string) Str::uuid7()); // a facility the user has no assignment for

    expect(fn () => dispatchAssignLocation((string) $tenant->id, null, $encounterId, (string) Str::uuid7(), null, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('department_id')))->toBeNull();
});

// ── isolation / not found ────────────────────────────────────────────

test('a missing / cross-tenant encounter is rejected with NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = locationFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = locationFixture();

    $encounterId = seedEncounterForLocation((string) $tenantA->id, (string) $facilityA->id);

    expect(fn () => dispatchAssignLocation((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7(), (string) Str::uuid7(), null, (string) $userA->id))
        ->toThrow(NotFound::class);

    expect(fn () => dispatchAssignLocation((string) $tenantB->id, (string) $facilityB->id, $encounterId, (string) Str::uuid7(), null, (string) $userB->id))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenantA->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('department_id')))->toBeNull();
});

// ── optimistic concurrency (IC §9.1) ────────────────────────────────

test('a stale in-memory assignLocation() save throws ConcurrencyConflict — no partial mutation, no event, no audit', function () {
    ['tenant' => $tenant, 'facility' => $facility] = locationFixture();
    $encounterId = seedEncounterForLocation((string) $tenant->id, (string) $facility->id);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId) {
            $stale = Encounter::query()->findOrFail($encounterId); // version 0
            Encounter::query()->whereKey($encounterId)->update(['version' => 1]); // another writer bumps it

            $stale->assignLocation((string) Str::uuid7(), null);
            app(EncounterRepository::class)->save($stale);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->department_id)->toBeNull();
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterLocationChanged::class)->count()))->toBe(0);
});

// ── active-attendance uniqueness — E.6 does NOT use ActiveEncounterExists ──

test('ARRIVED / ACTIVE location assignment never raises a uniqueness conflict — the handler has no uniqueness handling', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = locationFixture();
    $encounterId = seedEncounterForLocation((string) $tenant->id, (string) $facility->id);

    dispatchAssignLocation((string) $tenant->id, (string) $facility->id, $encounterId, (string) Str::uuid7(), null, (string) $user->id);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('department_id')))->not->toBeNull();

    $src = file_get_contents(base_path('app/Domains/Encounter/Application/Command/AssignEncounterLocationHandler.php'));
    expect($src)->not->toContain('ActiveEncounterExists')
        ->and($src)->not->toContain('UniqueConstraintViolationException')
        ->and($src)->not->toContain('try {');
});

// ── inpatient: care-context ward, NOT a bed ──────────────────────────

test('AdmitEncounter then AssignEncounterLocation sets the care-context ward with no bed / occupancy behavior', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = locationFixture([
        'encounter.encounters.admit', 'encounter.encounters.assign_location',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
        'capability_code' => 'INPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $encounterId = seedEncounterForLocation((string) $tenant->id, (string) $facility->id, EncounterStatus::Active, ['type' => EncounterType::Emergency]);
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new AdmitEncounter($encounterId, (string) $user->id)));

    $wardDepartmentId = (string) Str::uuid7();
    $wardUnitId = (string) Str::uuid7();
    dispatchAssignLocation((string) $tenant->id, (string) $facility->id, $encounterId, $wardDepartmentId, $wardUnitId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->encounter_type)->toBe('INPATIENT')
        ->and($row->status)->toBe('ACTIVE')
        ->and($row->department_id)->toBe($wardDepartmentId)
        ->and($row->unit_id)->toBe($wardUnitId)
        ->and((int) $row->version)->toBe(2); // admit (0->1), assign-location (1->2)

    // no bed/room column exists on the encounter, and E.6 wrote no bed occupancy anywhere
    $columns = Tenancy::run((string) $tenant->id, fn () => array_keys((array) DB::table('enc_encounters')->where('id', $encounterId)->first()));
    expect($columns)->not->toContain('bed_id')->and($columns)->not->toContain('room_id');
});

// ── composition ──────────────────────────────────────────────────────

test('CreateEncounter(OUTPATIENT) -> StartEncounter -> AssignEncounterLocation: location set, status ACTIVE, version 2', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = locationFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.assign_location', 'patient.patients.read',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
        'capability_code' => 'OUTPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Female])->id);

    $encounterId = (string) Str::uuid7();
    $departmentId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $departmentId, $user) {
        app(CommandBus::class)->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        app(CommandBus::class)->dispatch(new StartEncounter($encounterId, (string) $user->id));
        app(CommandBus::class)->dispatch(new AssignEncounterLocation($encounterId, (string) $user->id, $departmentId, null));
    });

    $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($encounterId));
    expect($encounter->department_id)->toBe($departmentId)
        ->and($encounter->status)->toBe(EncounterStatus::Active)
        ->and((int) $encounter->version)->toBe(2); // Start (0->1), AssignLocation (1->2)
});
