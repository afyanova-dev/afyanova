<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\CancelEncounter;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\ReopenEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Contracts\Event\EncounterCancelled;
use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
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
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 (increment E.9) gate — CancelEncounter ({PLANNED, ARRIVED} -> CANCELLED)
 | end-to-end through the CommandBus pipeline (IC §5): authorize (per-subject)
 | -> lifecycle guard (PLANNED or ARRIVED only) -> aggregate transition -> versioned
 | save -> outbox + audit, one transaction (11-encounter-domain.md §19, §23, §74;
 | ADR-121, ADR-130 A/D).
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function cancelFixture(array $permissions = ['encounter.encounters.cancel']): array
{
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser($permissions, []);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedEncounterForCancel(string $tenantId, string $facilityId, EncounterStatus $status, array $overrides = []): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient($overrides['patientId'] ?? (string) Str::uuid7())
        ->ofType($overrides['type'] ?? EncounterType::Outpatient)
        ->withStatus($status)
        ->create(array_merge([
            'arrived_at' => $status === EncounterStatus::Arrived ? CarbonImmutable::now()->subMinutes(30) : null,
        ], $overrides['attributes'] ?? []))->id);
}

function dispatchCancelEncounter(string $tenantId, ?string $facilityId, string $encounterId, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new CancelEncounter($encounterId, $actorId)));
}

// ── happy path — PLANNED / ARRIVED -> CANCELLED ───────────────────────

test('PLANNED / ARRIVED -> CANCELLED: status updated, preserved fields untouched, EncounterCancelled on outbox, audit row, version bumped', function (EncounterStatus $sourceStatus, EncounterType $type) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = cancelFixture();
    $patientId = (string) Str::uuid7();
    $encounterId = seedEncounterForCancel((string) $tenant->id, (string) $facility->id, $sourceStatus, [
        'patientId' => $patientId,
        'type' => $type,
        'attributes' => [
            'department_id' => (string) Str::uuid7(),
            'unit_id' => (string) Str::uuid7(),
        ],
    ]);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($before->status)->toBe($sourceStatus->value);

    dispatchCancelEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('CANCELLED')
        ->and((int) $row->version)->toBe(1)
        // disposition untouched (ADR-130 A — neither required nor set)
        ->and($row->disposition)->toBeNull()
        // arrived_at preserved as historical record (or null for PLANNED)
        ->and($row->arrived_at)->toBe($before->arrived_at)
        // other timestamps remain null
        ->and($row->started_at)->toBeNull()
        ->and($row->admitted_at)->toBeNull()
        ->and($row->completed_at)->toBeNull()
        // location and identity fields preserved
        ->and($row->encounter_type)->toBe($before->encounter_type)
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
        ->where('event_class', EncounterCancelled::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterCancelled')
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
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.cancelled')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('encounter')
        ->and($audit->target_id)->toBe($encounterId)
        ->and($audit->facility_id)->toBe((string) $facility->id)
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
})->with([
    'PLANNED / OUTPATIENT' => [EncounterStatus::Planned, EncounterType::Outpatient],
    'PLANNED / EMERGENCY' => [EncounterStatus::Planned, EncounterType::Emergency],
    'ARRIVED / OUTPATIENT' => [EncounterStatus::Arrived, EncounterType::Outpatient],
    'ARRIVED / EMERGENCY' => [EncounterStatus::Arrived, EncounterType::Emergency],
    'ARRIVED / INPATIENT' => [EncounterStatus::Arrived, EncounterType::Inpatient],
]);

// ── invalid source states ───────────────────────────────────────────

test('every source status except PLANNED and ARRIVED is rejected with IllegalEncounterTransition (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = cancelFixture();

    foreach (EncounterStatus::cases() as $status) {
        if (in_array($status, [EncounterStatus::Planned, EncounterStatus::Arrived], true)) {
            continue;
        }

        $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
            ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)
            ->forPatient((string) Str::uuid7())->ofType(EncounterType::Outpatient)
            ->withStatus($status)->create(['disposition' => EncounterDisposition::Discharged])->id);

        try {
            dispatchCancelEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);
            test()->fail("expected IllegalEncounterTransition for source status {$status->value}");
        } catch (IllegalEncounterTransition $e) {
            expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
        }

        $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
        expect($row->status)->toBe($status->value)->and((int) $row->version)->toBe(0);
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterCancelled::class)->count()))->toBe(0);
});

test('repeated cancel fails on the second execution because status is already CANCELLED (non-idempotent)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = cancelFixture();
    $encounterId = seedEncounterForCancel((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);

    dispatchCancelEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('CANCELLED');

    expect(fn () => dispatchCancelEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(IllegalEncounterTransition::class);
});

// ── authorization ───────────────────────────────────────────────────

test('it denies a caller without encounter.encounters.cancel (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = cancelFixture([]); // no keys
    $encounterId = seedEncounterForCancel((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);

    expect(fn () => dispatchCancelEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ARRIVED');
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.cancelled')->count()))->toBe(0);
});

test('the encounter.encounters.update key does NOT authorize cancel', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = cancelFixture(['encounter.encounters.update']);
    $encounterId = seedEncounterForCancel((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);

    expect(fn () => dispatchCancelEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('the encounter.encounters.complete key does NOT authorize cancel', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = cancelFixture(['encounter.encounters.complete']);
    $encounterId = seedEncounterForCancel((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);

    expect(fn () => dispatchCancelEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('the encounter.encounters.reopen key does NOT authorize cancel', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = cancelFixture(['encounter.encounters.reopen']);
    $encounterId = seedEncounterForCancel((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);

    expect(fn () => dispatchCancelEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('per-subject facility scope: a caller not assigned at the encounter facility is denied (Invariant 24/25)', function () {
    ['tenant' => $tenant, 'user' => $user] = cancelFixture(['encounter.encounters.cancel']);
    $encounterId = seedEncounterForCancel((string) $tenant->id, (string) Str::uuid7(), EncounterStatus::Arrived);

    expect(fn () => dispatchCancelEncounter((string) $tenant->id, null, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ARRIVED');
});

// ── isolation / not found ───────────────────────────────────────────

test('a missing / cross-tenant encounter is rejected with NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = cancelFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = cancelFixture();

    $encounterId = seedEncounterForCancel((string) $tenantA->id, (string) $facilityA->id, EncounterStatus::Arrived);

    expect(fn () => dispatchCancelEncounter((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7(), (string) $userA->id))
        ->toThrow(NotFound::class);

    expect(fn () => dispatchCancelEncounter((string) $tenantB->id, (string) $facilityB->id, $encounterId, (string) $userB->id))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenantA->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ARRIVED');
});

// ── optimistic concurrency ──────────────────────────────────────────

test('a stale in-memory cancel() save throws ConcurrencyConflict — no partial mutation, no event, no audit', function () {
    ['tenant' => $tenant, 'facility' => $facility] = cancelFixture();
    $encounterId = seedEncounterForCancel((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId) {
            $stale = Encounter::query()->findOrFail($encounterId); // version 0
            Encounter::query()->whereKey($encounterId)->update(['version' => 1]); // another writer bumps it

            $stale->cancel();
            app(EncounterRepository::class)->save($stale);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ARRIVED');
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterCancelled::class)->count()))->toBe(0);
});

// ── active-attendance uniqueness behavior ────────────────────────────

test('cancelling an ARRIVED encounter exits the active-attendance predicate, allowing a new encounter for the same patient at that facility', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = cancelFixture([
        'encounter.encounters.create', 'encounter.encounters.cancel', 'patient.patients.read',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
        'capability_code' => 'OUTPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Male])->id);

    // 1. Create walk-in encounter (status = ARRIVED)
    $encounter1 = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounter1, $patientId, $user) {
        app(CommandBus::class)->dispatch(new CreateEncounter($encounter1, $patientId, (string) $user->id, EncounterType::Outpatient));
    });

    // 2. Cancel the encounter
    dispatchCancelEncounter((string) $tenant->id, (string) $facility->id, $encounter1, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounter1)->value('status')))->toBe('CANCELLED');

    // 3. Create a new encounter for the same patient at the same facility — must SUCCEED because encounter1 exited the predicate
    $encounter2 = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounter2, $patientId, $user) {
        app(CommandBus::class)->dispatch(new CreateEncounter($encounter2, $patientId, (string) $user->id, EncounterType::Outpatient));
    });

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounter2)->value('status')))->toBe('ARRIVED');
});

// ── lifecycle composition & E.8 compatibility ────────────────────────

test('lifecycle composition: CreateEncounter(planned) -> CancelEncounter reaches CANCELLED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = cancelFixture([
        'encounter.encounters.create', 'encounter.encounters.cancel', 'patient.patients.read',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
        'capability_code' => 'OUTPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Female])->id);

    $encounterId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient, planned: true));
        $bus->dispatch(new CancelEncounter($encounterId, (string) $user->id));
    });

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('CANCELLED')
        ->and($row->arrived_at)->toBeNull()
        ->and((int) $row->version)->toBe(1);
});

test('lifecycle composition: CreateEncounter(walk-in) -> CancelEncounter reaches CANCELLED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = cancelFixture([
        'encounter.encounters.create', 'encounter.encounters.cancel', 'patient.patients.read',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
        'capability_code' => 'OUTPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Female])->id);

    $encounterId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new CancelEncounter($encounterId, (string) $user->id));
    });

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('CANCELLED')
        ->and($row->arrived_at)->not->toBeNull()
        ->and((int) $row->version)->toBe(1);
});

test('E.8 compatibility: Complete -> Reopen -> Cancel is rejected (ACTIVE status cannot be cancelled)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = cancelFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.manage_disposition',
        'encounter.encounters.complete', 'encounter.encounters.reopen', 'encounter.encounters.cancel', 'patient.patients.read',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
        'capability_code' => 'OUTPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Female])->id);

    $encounterId = (string) Str::uuid7();

    // 1. Create -> Start -> ChangeDisposition -> Complete -> Reopen
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
        $bus->dispatch(new ReopenEncounter($encounterId, (string) $user->id));
    });

    $reopenedRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($reopenedRow->status)->toBe('ACTIVE');

    // 2. Attempt Cancel on reopened ACTIVE encounter must be rejected
    expect(fn () => dispatchCancelEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(IllegalEncounterTransition::class);

    $finalRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($finalRow->status)->toBe('ACTIVE');
});

test('E.8 compatibility: Cancelled encounter cannot be reopened (assertCanReopen rejects CANCELLED)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = cancelFixture([
        'encounter.encounters.cancel', 'encounter.encounters.reopen',
    ]);
    $encounterId = seedEncounterForCancel((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);

    dispatchCancelEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new ReopenEncounter($encounterId, (string) $user->id))))
        ->toThrow(IllegalEncounterTransition::class);
});
