<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\AdmitEncounter;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Contracts\Event\EncounterCompleted;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 (increment E.7) gate — CompleteEncounter (ACTIVE & disposition set ->
 | COMPLETED) end-to-end through the real CommandBus pipeline (IC §5): authorize
 | (per-subject) -> lifecycle guard (status + disposition) -> aggregate transition
 | -> versioned save -> outbox + audit, one transaction (11-encounter-domain.md
 | §19, §22, §67, §73; ADR-121, ADR-130). First command to EXIT the
 | enc_one_active_per_patient_facility predicate. NO downstream-workflow
 | completion (Invariant 18).
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function completeFixture(array $permissions = ['encounter.encounters.complete']): array
{
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser($permissions, []);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedActiveEncounterForCompletion(string $tenantId, string $facilityId, ?EncounterDisposition $disposition, array $overrides = []): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient($overrides['patientId'] ?? (string) Str::uuid7())
        ->ofType($overrides['type'] ?? EncounterType::Outpatient)
        ->active()
        ->create(array_merge(['disposition' => $disposition], $overrides['attributes'] ?? []))->id);
}

function dispatchCompleteEncounter(string $tenantId, ?string $facilityId, string $encounterId, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new CompleteEncounter($encounterId, $actorId)));
}

// ── happy path — every non-null disposition ──────────────────────────

test('ACTIVE + <disposition> -> COMPLETED: completed_at set, disposition preserved, EncounterCompleted on the outbox, audit (metadata []), version bumped', function (EncounterDisposition $disposition, EncounterType $type) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = completeFixture();
    $patientId = (string) Str::uuid7();
    $encounterId = seedActiveEncounterForCompletion((string) $tenant->id, (string) $facility->id, $disposition, [
        'patientId' => $patientId, 'type' => $type,
        'attributes' => ['department_id' => (string) Str::uuid7(), 'unit_id' => (string) Str::uuid7()],
    ]);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($before->status)->toBe('ACTIVE')->and($before->completed_at)->toBeNull();

    dispatchCompleteEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('COMPLETED')
        ->and($row->completed_at)->not->toBeNull()
        ->and($row->disposition)->toBe($disposition->value)   // precondition — unchanged
        ->and((int) $row->version)->toBe(1)
        // type / transition timestamps / location / identity untouched
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

    $isFresh = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')
        ->where('id', $encounterId)->whereNotNull('completed_at')
        ->where('completed_at', '>=', now()->subMinutes(5))->where('completed_at', '<=', now()->addMinutes(5))->exists());
    expect($isFresh)->toBeTrue();

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterCompleted::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterCompleted')
        ->and($outbox->published_at)->toBeNull();
    $payload = json_decode((string) $outbox->payload, true);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId',
        'disposition', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['encounterId'])->toBe($encounterId)
        ->and($payload['patientId'])->toBe($patientId)
        ->and($payload['facilityId'])->toBe((string) $facility->id)
        ->and($payload['disposition'])->toBe($disposition->value);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.completed')->first());
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
    'ADMITTED / INPATIENT (no discharge revision)' => [EncounterDisposition::Admitted, EncounterType::Inpatient],
]);

// ── null disposition ────────────────────────────────────────────────

test('ACTIVE + null disposition -> rejected (409 illegal_encounter_transition); no state / event / audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = completeFixture();
    $encounterId = seedActiveEncounterForCompletion((string) $tenant->id, (string) $facility->id, null);

    try {
        dispatchCompleteEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);
        test()->fail('expected IllegalEncounterTransition');
    } catch (IllegalEncounterTransition $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
    }

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ACTIVE')->and($row->completed_at)->toBeNull()->and((int) $row->version)->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterCompleted::class)->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.completed')->count()))->toBe(0);
});

// ── invalid source states ───────────────────────────────────────────

test('every source status except ACTIVE is rejected with IllegalEncounterTransition (409); COMPLETED -> Complete is NOT idempotent', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = completeFixture();

    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Active) {
            continue;
        }

        $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
            ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)
            ->forPatient((string) Str::uuid7())->ofType(EncounterType::Outpatient)
            ->withStatus($status)->create(['disposition' => EncounterDisposition::Discharged])->id);

        try {
            dispatchCompleteEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);
            test()->fail("expected IllegalEncounterTransition for source status {$status->value}");
        } catch (IllegalEncounterTransition $e) {
            expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
        }

        $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
        expect($row->status)->toBe($status->value)->and((int) $row->version)->toBe(0);
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterCompleted::class)->count()))->toBe(0);
});

// ── authorization ───────────────────────────────────────────────────

test('it denies a caller without encounter.encounters.complete (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = completeFixture([]); // no keys
    $encounterId = seedActiveEncounterForCompletion((string) $tenant->id, (string) $facility->id, EncounterDisposition::Discharged);

    expect(fn () => dispatchCompleteEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ACTIVE');
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.completed')->count()))->toBe(0);
});

test('the encounter.encounters.update key does NOT authorize completion — it needs its own dedicated key', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = completeFixture(['encounter.encounters.update']);
    $encounterId = seedActiveEncounterForCompletion((string) $tenant->id, (string) $facility->id, EncounterDisposition::Discharged);

    expect(fn () => dispatchCompleteEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('per-subject facility scope: a caller not assigned at the encounter facility is denied (Invariant 24/25)', function () {
    ['tenant' => $tenant, 'user' => $user] = completeFixture(['encounter.encounters.complete']);
    $encounterId = seedActiveEncounterForCompletion((string) $tenant->id, (string) Str::uuid7(), EncounterDisposition::Discharged);

    expect(fn () => dispatchCompleteEncounter((string) $tenant->id, null, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ACTIVE');
});

// ── isolation / not found ───────────────────────────────────────────

test('a missing / cross-tenant encounter is rejected with NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = completeFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = completeFixture();

    $encounterId = seedActiveEncounterForCompletion((string) $tenantA->id, (string) $facilityA->id, EncounterDisposition::Discharged);

    expect(fn () => dispatchCompleteEncounter((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7(), (string) $userA->id))
        ->toThrow(NotFound::class);

    expect(fn () => dispatchCompleteEncounter((string) $tenantB->id, (string) $facilityB->id, $encounterId, (string) $userB->id))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenantA->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ACTIVE');
});

// ── optimistic concurrency (IC §9.1; §144) ──────────────────────────

test('a stale in-memory complete() save throws ConcurrencyConflict — no partial mutation, no event, no audit', function () {
    ['tenant' => $tenant, 'facility' => $facility] = completeFixture();
    $encounterId = seedActiveEncounterForCompletion((string) $tenant->id, (string) $facility->id, EncounterDisposition::Discharged);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId) {
            $stale = Encounter::query()->findOrFail($encounterId); // version 0
            Encounter::query()->whereKey($encounterId)->update(['version' => 1]); // another writer bumps it

            $stale->complete();
            app(EncounterRepository::class)->save($stale);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ACTIVE')->and($row->completed_at)->toBeNull();
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterCompleted::class)->count()))->toBe(0);
});

// ── active-attendance uniqueness — E.7 EXITS the predicate ───────────

test('ACTIVE -> COMPLETED leaves the active-attendance predicate — no uniqueness conflict, no uniqueness handling', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = completeFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.manage_disposition',
        'encounter.encounters.complete', 'patient.patients.read',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
        'capability_code' => 'OUTPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Male])->id);

    // complete the first encounter, then a second CreateEncounter for the same (patient, facility) must succeed
    $first = (string) Str::uuid7();
    $second = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($first, $second, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($first, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($first, (string) $user->id));
        $bus->dispatch(new ChangeEncounterDisposition($first, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($first, (string) $user->id));
        $bus->dispatch(new CreateEncounter($second, $patientId, (string) $user->id, EncounterType::Outpatient)); // must NOT hit ActiveEncounterExists
    });

    $rows = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('patient_id', $patientId)->pluck('status', 'id'));
    expect($rows[$first])->toBe('COMPLETED')->and($rows[$second])->toBe('ARRIVED');

    $src = file_get_contents(base_path('app/Domains/Encounter/Application/Command/CompleteEncounterHandler.php'));
    expect($src)->not->toContain('ActiveEncounterExists')
        ->and($src)->not->toContain('UniqueConstraintViolationException')
        ->and($src)->not->toContain('try {');
});

// ── composition ─────────────────────────────────────────────────────

test('CreateEncounter(OUTPATIENT) -> StartEncounter -> CompleteEncounter (no disposition) -> rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = completeFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.complete', 'patient.patients.read',
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
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
    });

    expect(fn () => dispatchCompleteEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(IllegalEncounterTransition::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ACTIVE');
});

test('CreateEncounter -> Start -> ChangeDisposition(DISCHARGED) -> Complete: COMPLETED / DISCHARGED, ordered timestamps, version 3', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = completeFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.manage_disposition',
        'encounter.encounters.complete', 'patient.patients.read',
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
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($encounterId));
    expect($encounter->status)->toBe(EncounterStatus::Completed)
        ->and($encounter->disposition)->toBe(EncounterDisposition::Discharged)
        ->and($encounter->completed_at)->not->toBeNull()
        ->and($encounter->completed_at->greaterThanOrEqualTo($encounter->started_at))->toBeTrue()
        ->and((int) $encounter->version)->toBe(3); // Start (0->1), ChangeDisposition (1->2), Complete (2->3)
});

test('CreateEncounter(EMERGENCY) -> Start -> Admit -> Complete: COMPLETED / INPATIENT / ADMITTED, admitted_at preserved, version 3', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = completeFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.admit',
        'encounter.encounters.complete', 'patient.patients.read',
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
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Emergency));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
        $bus->dispatch(new AdmitEncounter($encounterId, (string) $user->id));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($encounterId));
    expect($encounter->status)->toBe(EncounterStatus::Completed)
        ->and($encounter->encounter_type)->toBe(EncounterType::Inpatient)
        ->and($encounter->disposition)->toBe(EncounterDisposition::Admitted)
        ->and($encounter->admitted_at)->not->toBeNull()
        ->and($encounter->completed_at->greaterThanOrEqualTo($encounter->admitted_at))->toBeTrue()
        ->and((int) $encounter->version)->toBe(3); // Start, Admit, Complete
});

// ── validation ──────────────────────────────────────────────────────

test('CompleteEncounter rejects malformed ids at construction (IC §3)', function () {
    expect(fn () => new CompleteEncounter('nope', (string) Str::uuid7()))->toThrow(InvalidArgumentException::class);
});
