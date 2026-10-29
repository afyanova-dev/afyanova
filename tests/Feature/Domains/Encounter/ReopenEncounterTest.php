<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\AdmitEncounter;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\ReopenEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Contracts\Event\EncounterCompleted;
use App\Domains\Encounter\Contracts\Event\EncounterReopened;
use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Exception\ActiveEncounterExists;
use App\Domains\Encounter\Domain\Exception\IllegalEncounterTransition;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Concurrency\ConcurrencyConflict;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 (increment E.8) gate — ReopenEncounter (COMPLETED -> ACTIVE)
 | end-to-end through the CommandBus pipeline (IC §5): authorize (per-subject)
 | -> lifecycle guard (COMPLETED only) -> aggregate transition -> versioned
 | save catching enc_one_active_per_patient_facility collision -> outbox +
 | audit, one transaction (11-encounter-domain.md §19, §68, §88; ADR-121,
 | ADR-130 C, Rulings E8-R1..E8-R8).
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function reopenFixture(array $permissions = ['encounter.encounters.reopen']): array
{
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser($permissions, []);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedCompletedEncounterForReopen(string $tenantId, string $facilityId, ?EncounterDisposition $disposition = EncounterDisposition::Discharged, array $overrides = []): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient($overrides['patientId'] ?? (string) Str::uuid7())
        ->ofType($overrides['type'] ?? EncounterType::Outpatient)
        ->completed()
        ->create(array_merge([
            'disposition' => $disposition,
            'completed_at' => $overrides['completed_at'] ?? CarbonImmutable::now()->subHour(),
        ], $overrides['attributes'] ?? []))->id);
}

function dispatchReopenEncounter(string $tenantId, ?string $facilityId, string $encounterId, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new ReopenEncounter($encounterId, $actorId)));
}

// ── happy path — COMPLETED -> ACTIVE ─────────────────────────────────

test('COMPLETED + <disposition> -> ACTIVE: completed_at preserved, disposition preserved, EncounterReopened on outbox, audit (metadata []), version bumped', function (EncounterDisposition $disposition, EncounterType $type) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = reopenFixture();
    $patientId = (string) Str::uuid7();
    $originalCompletedAt = CarbonImmutable::now()->subHours(2);
    $encounterId = seedCompletedEncounterForReopen((string) $tenant->id, (string) $facility->id, $disposition, [
        'patientId' => $patientId,
        'type' => $type,
        'completed_at' => $originalCompletedAt,
        'attributes' => [
            'department_id' => (string) Str::uuid7(),
            'unit_id' => (string) Str::uuid7(),
        ],
    ]);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($before->status)->toBe('COMPLETED')
        ->and($before->completed_at)->not->toBeNull()
        ->and($before->disposition)->toBe($disposition->value);

    dispatchReopenEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ACTIVE')
        // completed_at is preserved unchanged (Ruling E8-R1, Invariant 16)
        ->and($row->completed_at)->toBe($before->completed_at)
        // disposition, type, other timestamps, location, identity untouched
        ->and($row->disposition)->toBe($disposition->value)
        ->and((int) $row->version)->toBe(1)
        ->and($row->encounter_type)->toBe($before->encounter_type)
        ->and($row->arrived_at)->toBe($before->arrived_at)
        ->and($row->started_at)->toBe($before->started_at)
        ->and($row->admitted_at)->toBe($before->admitted_at)
        ->and($row->department_id)->toBe($before->department_id)
        ->and($row->unit_id)->toBe($before->unit_id)
        ->and($row->patient_id)->toBe($before->patient_id)
        ->and($row->facility_id)->toBe($before->facility_id)
        ->and($row->encounter_number)->toBe($before->encounter_number)
        ->and($row->created_at)->toBe($before->created_at)
        ->and($row->created_by_user_id)->toBe($before->created_by_user_id)
        ->and($row->created_source)->toBe($before->created_source);

    // Event outbox verification
    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterReopened::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterReopened')
        ->and($outbox->published_at)->toBeNull();
    $payload = json_decode((string) $outbox->payload, true);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId',
        'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['encounterId'])->toBe($encounterId)
        ->and($payload['patientId'])->toBe($patientId)
        ->and($payload['facilityId'])->toBe((string) $facility->id);

    // Audit log verification
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.reopened')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('encounter')
        ->and($audit->target_id)->toBe($encounterId)
        ->and($audit->facility_id)->toBe((string) $facility->id)
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
})->with([
    'DISCHARGED / OUTPATIENT' => [EncounterDisposition::Discharged, EncounterType::Outpatient],
    'REFERRED / EMERGENCY' => [EncounterDisposition::Referred, EncounterType::Emergency],
    'LEFT / OUTPATIENT' => [EncounterDisposition::Left, EncounterType::Outpatient],
    'FOLLOW_UP / OUTPATIENT' => [EncounterDisposition::FollowUp, EncounterType::Outpatient],
    'DECEASED / EMERGENCY' => [EncounterDisposition::Deceased, EncounterType::Emergency],
    'OTHER / OUTPATIENT' => [EncounterDisposition::Other, EncounterType::Outpatient],
    'ADMITTED / INPATIENT' => [EncounterDisposition::Admitted, EncounterType::Inpatient],
]);

// ── invalid source states ───────────────────────────────────────────

test('every source status except COMPLETED is rejected with IllegalEncounterTransition (409); ACTIVE -> Reopen is NOT idempotent', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = reopenFixture();

    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Completed) {
            continue;
        }

        $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
            ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)
            ->forPatient((string) Str::uuid7())->ofType(EncounterType::Outpatient)
            ->withStatus($status)->create(['disposition' => EncounterDisposition::Discharged])->id);

        try {
            dispatchReopenEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);
            test()->fail("expected IllegalEncounterTransition for source status {$status->value}");
        } catch (IllegalEncounterTransition $e) {
            expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
        }

        $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
        expect($row->status)->toBe($status->value)->and((int) $row->version)->toBe(0);
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterReopened::class)->count()))->toBe(0);
});

test('repeated reopen fails on the second execution because status is already ACTIVE', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = reopenFixture();
    $encounterId = seedCompletedEncounterForReopen((string) $tenant->id, (string) $facility->id);

    dispatchReopenEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ACTIVE');

    expect(fn () => dispatchReopenEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(IllegalEncounterTransition::class);
});

// ── authorization ───────────────────────────────────────────────────

test('it denies a caller without encounter.encounters.reopen (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = reopenFixture([]); // no keys
    $encounterId = seedCompletedEncounterForReopen((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchReopenEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('COMPLETED');
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.reopened')->count()))->toBe(0);
});

test('the encounter.encounters.update key does NOT authorize reopen', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = reopenFixture(['encounter.encounters.update']);
    $encounterId = seedCompletedEncounterForReopen((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchReopenEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('the encounter.encounters.complete key does NOT authorize reopen', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = reopenFixture(['encounter.encounters.complete']);
    $encounterId = seedCompletedEncounterForReopen((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchReopenEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('per-subject facility scope: a caller not assigned at the encounter facility is denied (Invariant 24/25)', function () {
    ['tenant' => $tenant, 'user' => $user] = reopenFixture(['encounter.encounters.reopen']);
    $encounterId = seedCompletedEncounterForReopen((string) $tenant->id, (string) Str::uuid7());

    expect(fn () => dispatchReopenEncounter((string) $tenant->id, null, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('COMPLETED');
});

// ── isolation / not found ───────────────────────────────────────────

test('a missing / cross-tenant encounter is rejected with NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = reopenFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = reopenFixture();

    $encounterId = seedCompletedEncounterForReopen((string) $tenantA->id, (string) $facilityA->id);

    expect(fn () => dispatchReopenEncounter((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7(), (string) $userA->id))
        ->toThrow(NotFound::class);

    expect(fn () => dispatchReopenEncounter((string) $tenantB->id, (string) $facilityB->id, $encounterId, (string) $userB->id))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenantA->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('COMPLETED');
});

// ── optimistic concurrency ──────────────────────────────────────────

test('a stale in-memory reopen() save throws ConcurrencyConflict — no partial mutation, no event, no audit', function () {
    ['tenant' => $tenant, 'facility' => $facility] = reopenFixture();
    $encounterId = seedCompletedEncounterForReopen((string) $tenant->id, (string) $facility->id);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId) {
            $stale = Encounter::query()->findOrFail($encounterId); // version 0
            Encounter::query()->whereKey($encounterId)->update(['version' => 1]); // another writer bumps it

            $stale->reopen();
            app(EncounterRepository::class)->save($stale);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('COMPLETED');
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterReopened::class)->count()))->toBe(0);
});

// ── active-attendance uniqueness collision (Ruling E8-R4) ────────────

test('reopening a completed encounter when the patient already has an active encounter at that facility raises ActiveEncounterExists (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = reopenFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.manage_disposition',
        'encounter.encounters.complete', 'encounter.encounters.reopen', 'patient.patients.read',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
        'capability_code' => 'OUTPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Male])->id);

    // 1. Create and complete encounter A
    $encounterA = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterA, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterA, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterA, (string) $user->id));
        $bus->dispatch(new ChangeEncounterDisposition($encounterA, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterA, (string) $user->id));
    });

    // 2. Create new active encounter B for same patient at same facility
    $encounterB = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterB, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterB, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterB, (string) $user->id));
    });

    // 3. Attempting to reopen encounter A must hit ActiveEncounterExists because B is currently active
    try {
        dispatchReopenEncounter((string) $tenant->id, (string) $facility->id, $encounterA, (string) $user->id);
        test()->fail('expected ActiveEncounterExists');
    } catch (ActiveEncounterExists $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('active_encounter_exists');
    }

    // Confirm encounter A remains COMPLETED and no EncounterReopened was persisted
    $rowA = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterA)->first());
    expect($rowA->status)->toBe('COMPLETED');
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterReopened::class)->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.reopened')->count()))->toBe(0);
});

// ── Complete -> Reopen -> Complete cycle & completed_at immutability ──

test('Complete -> Reopen -> Complete: completed_at remains ORIGINAL timestamp (Ruling E8-R1), new event & audit emitted, version 4', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = reopenFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.manage_disposition',
        'encounter.encounters.complete', 'encounter.encounters.reopen', 'patient.patients.read',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
        'capability_code' => 'OUTPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Female])->id);

    $encounterId = (string) Str::uuid7();

    // 1. Create -> Start -> ChangeDisposition -> Complete
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    $firstCompleteRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    $originalCompletedAt = $firstCompleteRow->completed_at;
    expect($firstCompleteRow->status)->toBe('COMPLETED')
        ->and($originalCompletedAt)->not->toBeNull()
        ->and((int) $firstCompleteRow->version)->toBe(3);

    // 2. Reopen
    dispatchReopenEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $reopenedRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($reopenedRow->status)->toBe('ACTIVE')
        ->and($reopenedRow->completed_at)->toBe($originalCompletedAt) // preserved!
        ->and((int) $reopenedRow->version)->toBe(4);

    // 3. Second Complete (without changing disposition — existing disposition satisfies precondition)
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        app(CommandBus::class)->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    $secondCompleteRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($secondCompleteRow->status)->toBe('COMPLETED')
        ->and($secondCompleteRow->completed_at)->toBe($originalCompletedAt) // STILL original timestamp! (Ruling E8-R1, Invariant 16)
        ->and((int) $secondCompleteRow->version)->toBe(5);

    // Verify two EncounterCompleted events exist in outbox
    $completedEvents = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterCompleted::class)->orderBy('created_at')->get());
    expect($completedEvents)->toHaveCount(2);

    // Verify two encounter.completed audit entries exist
    $completedAudits = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'encounter.completed')->orderBy('created_at')->get());
    expect($completedAudits)->toHaveCount(2);

    // Verify exactly one encounter.reopened audit entry exists
    $reopenedAudits = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'encounter.reopened')->get());
    expect($reopenedAudits)->toHaveCount(1);
});

// ── Inpatient Complete -> Reopen -> Complete preserves INPATIENT ─────

test('Inpatient Create(EMERGENCY) -> Start -> Admit -> Complete -> Reopen -> Complete preserves INPATIENT and admitted_at', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = reopenFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.admit',
        'encounter.encounters.complete', 'encounter.encounters.reopen', 'patient.patients.read',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        ['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
            'capability_code' => 'EMERGENCY_CARE', 'state' => 'ENABLED', 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
            'capability_code' => 'INPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]));

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Female])->id);

    $encounterId = (string) Str::uuid7();

    // 1. Create -> Start -> Admit -> Complete
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Emergency));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
        $bus->dispatch(new AdmitEncounter($encounterId, (string) $user->id));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    $admittedRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($admittedRow->status)->toBe('COMPLETED')
        ->and($admittedRow->encounter_type)->toBe('INPATIENT')
        ->and($admittedRow->disposition)->toBe('ADMITTED')
        ->and($admittedRow->admitted_at)->not->toBeNull();

    // 2. Reopen
    dispatchReopenEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $reopenedRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($reopenedRow->status)->toBe('ACTIVE')
        ->and($reopenedRow->encounter_type)->toBe('INPATIENT') // admission NOT reversed (Ruling E8-R3)
        ->and($reopenedRow->admitted_at)->toBe($admittedRow->admitted_at)
        ->and($reopenedRow->disposition)->toBe('ADMITTED');

    // 3. Complete again
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        app(CommandBus::class)->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    $finalRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($finalRow->status)->toBe('COMPLETED')
        ->and($finalRow->encounter_type)->toBe('INPATIENT')
        ->and($finalRow->admitted_at)->toBe($admittedRow->admitted_at)
        ->and($finalRow->completed_at)->toBe($admittedRow->completed_at);
});
