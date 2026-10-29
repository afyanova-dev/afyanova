<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\AbandonEncounter;
use App\Domains\Encounter\Application\Command\AdmitEncounter;
use App\Domains\Encounter\Application\Command\CancelEncounter;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\ReopenEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Application\Command\TransferEncounter;
use App\Domains\Encounter\Contracts\Event\EncounterTransferred;
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
 | PHASE 5 (increment E.11) gate — TransferEncounter (ACTIVE -> TRANSFERRED,
 | disposition -> TRANSFERRED) end-to-end through the real CommandBus pipeline
 | (IC §5): authorize (per-subject) -> lifecycle guard (ACTIVE only) -> aggregate
 | transition -> versioned save -> outbox + audit, one transaction
 | (11-encounter-domain.md §19, §25, §69, §90, §91, §169; ADR-121, ADR-130 A/C/E).
 | Terminal state, non-idempotent. EXITS the enc_one_active_per_patient_facility
 | predicate. The receiving facility opens its OWN encounter (§8, §91) — E.11
 | creates neither it nor a link row. NO downstream-workflow completion
 | (Invariant 18).
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function encounterTransferFixture(array $permissions = ['encounter.encounters.transfer']): array
{
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser($permissions, []);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedActiveEncounterForTransfer(string $tenantId, string $facilityId, array $overrides = []): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient($overrides['patientId'] ?? (string) Str::uuid7())
        ->ofType($overrides['type'] ?? EncounterType::Outpatient)
        ->active()
        ->create($overrides['attributes'] ?? [])->id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedEncounterWithStatusForTransfer(string $tenantId, string $facilityId, EncounterStatus $status, array $overrides = []): string
{
    $arrivedAt = match ($status) {
        EncounterStatus::Planned => null,
        default => CarbonImmutable::now()->subMinutes(60),
    };
    $startedAt = match ($status) {
        EncounterStatus::Active, EncounterStatus::Completed, EncounterStatus::Abandoned, EncounterStatus::Transferred, EncounterStatus::Referred => CarbonImmutable::now()->subMinutes(30),
        default => null,
    };

    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient($overrides['patientId'] ?? (string) Str::uuid7())
        ->ofType($overrides['type'] ?? EncounterType::Outpatient)
        ->withStatus($status)
        ->create(array_merge([
            'arrived_at' => $arrivedAt,
            'started_at' => $startedAt,
            'completed_at' => $status === EncounterStatus::Completed ? CarbonImmutable::now()->subMinutes(10) : null,
            'disposition' => $status === EncounterStatus::Completed ? EncounterDisposition::Discharged->value : null,
        ], $overrides['attributes'] ?? []))->id);
}

function dispatchTransferEncounter(string $tenantId, ?string $facilityId, string $encounterId, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new TransferEncounter($encounterId, $actorId)));
}

// ── happy path — ACTIVE -> TRANSFERRED ────────────────────────────────

test('ACTIVE -> TRANSFERRED: status + disposition updated, preserved fields untouched, completed_at null, EncounterTransferred on outbox, audit row, version bumped', function (EncounterType $type) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterTransferFixture();
    $patientId = (string) Str::uuid7();
    $encounterId = seedActiveEncounterForTransfer((string) $tenant->id, (string) $facility->id, [
        'patientId' => $patientId,
        'type' => $type,
        'attributes' => [
            'department_id' => (string) Str::uuid7(),
            'unit_id' => (string) Str::uuid7(),
        ],
    ]);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($before->status)->toBe('ACTIVE')->and($before->disposition)->toBeNull();

    dispatchTransferEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('TRANSFERRED')
        ->and($row->disposition)->toBe('TRANSFERRED')
        ->and((int) $row->version)->toBe(1)
        // completed_at stays null — the encounter transferred, it did not complete
        ->and($row->completed_at)->toBeNull()
        // transition timestamps preserved as historical record
        ->and($row->arrived_at)->toBe($before->arrived_at)
        ->and($row->started_at)->toBe($before->started_at)
        ->and($row->admitted_at)->toBe($before->admitted_at)
        // type / location / identity / provenance untouched
        ->and($row->encounter_type)->toBe($type->value)
        ->and($row->department_id)->toBe($before->department_id)
        ->and($row->unit_id)->toBe($before->unit_id)
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->facility_id)->toBe((string) $facility->id)
        ->and($row->encounter_number)->toBe($before->encounter_number)
        ->and($row->created_by_user_id)->toBe($before->created_by_user_id)
        ->and($row->created_source)->toBe($before->created_source)
        ->and($row->created_at)->toBe($before->created_at);

    // outbox event written atomically
    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterTransferred::class)
        ->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterTransferred')
        ->and($outbox->tenant_id)->toBe((string) $tenant->id)
        ->and($outbox->published_at)->toBeNull();

    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['encounterId'])->toBe($encounterId)
        ->and($payload['tenantId'])->toBe((string) $tenant->id)
        ->and($payload['facilityId'])->toBe((string) $facility->id)
        ->and($payload['patientId'])->toBe($patientId);

    // audit row written
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'encounter.transferred')
        ->first());
    expect($audit)->not->toBeNull()
        ->and($audit->target_id)->toBe($encounterId)
        ->and($audit->target_type)->toBe('encounter')
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->facility_id)->toBe((string) $facility->id)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
})->with([
    'OUTPATIENT' => EncounterType::Outpatient,
    'EMERGENCY' => EncounterType::Emergency,
    'INPATIENT' => EncounterType::Inpatient,
    'DIAGNOSTIC' => EncounterType::Diagnostic,
]);

// ── disposition overwrite ────────────────────────────────────────────

test('ACTIVE (INPATIENT, disposition ADMITTED) -> TRANSFERRED: disposition overwritten to TRANSFERRED, admitted_at preserved', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterTransferFixture();
    $encounterId = seedActiveEncounterForTransfer((string) $tenant->id, (string) $facility->id, [
        'type' => EncounterType::Inpatient,
        'attributes' => [
            'disposition' => EncounterDisposition::Admitted->value,
            'admitted_at' => CarbonImmutable::now()->subMinutes(20),
        ],
    ]);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($before->disposition)->toBe('ADMITTED')->and($before->admitted_at)->not->toBeNull();

    dispatchTransferEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('TRANSFERRED')
        ->and($row->disposition)->toBe('TRANSFERRED')
        ->and($row->encounter_type)->toBe('INPATIENT')
        ->and($row->admitted_at)->toBe($before->admitted_at);
});

// ── invalid source statuses ──────────────────────────────────────────

test('every source status except ACTIVE is rejected with IllegalEncounterTransition (409); no mutation / event / audit', function (EncounterStatus $invalidSource) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterTransferFixture();
    $encounterId = seedEncounterWithStatusForTransfer((string) $tenant->id, (string) $facility->id, $invalidSource);

    try {
        dispatchTransferEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);
        $this->fail("expected IllegalEncounterTransition for {$invalidSource->value}");
    } catch (IllegalEncounterTransition $e) {
        expect($e->httpStatus())->toBe(409)
            ->and($e->errorCode())->toBe('illegal_encounter_transition');
    }

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe($invalidSource->value)
        ->and((int) $row->version)->toBe(0);

    $outboxCount = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count());
    $auditCount = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->count());
    expect($outboxCount)->toBe(0)->and($auditCount)->toBe(0);
})->with([
    'PLANNED' => EncounterStatus::Planned,
    'ARRIVED' => EncounterStatus::Arrived,
    'COMPLETED' => EncounterStatus::Completed,
    'CANCELLED' => EncounterStatus::Cancelled,
    'LEFT_WITHOUT_BEING_SEEN' => EncounterStatus::LeftWithoutBeingSeen,
    'ABANDONED' => EncounterStatus::Abandoned,
    'TRANSFERRED' => EncounterStatus::Transferred,
    'REFERRED' => EncounterStatus::Referred,
]);

// ── non-idempotency ──────────────────────────────────────────────────

test('repeated transfer fails on the second execution because TRANSFERRED is terminal (ADR-121)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterTransferFixture();
    $encounterId = seedActiveEncounterForTransfer((string) $tenant->id, (string) $facility->id);

    dispatchTransferEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('TRANSFERRED')->and((int) $row->version)->toBe(1);

    expect(fn () => dispatchTransferEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(IllegalEncounterTransition::class);

    $rowAfter = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($rowAfter->status)->toBe('TRANSFERRED')
        ->and((int) $rowAfter->version)->toBe(1)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterTransferred::class)->count()))->toBe(1)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.transferred')->count()))->toBe(1);
});

// ── authorization ────────────────────────────────────────────────────

test('it denies a caller without encounter.encounters.transfer (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterTransferFixture([]);
    $encounterId = seedActiveEncounterForTransfer((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchTransferEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ACTIVE');
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.transferred')->count()))->toBe(0);
});

test('no sibling lifecycle key authorizes transfer — it needs its own dedicated key', function (string $siblingKey) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterTransferFixture([$siblingKey]);
    $encounterId = seedActiveEncounterForTransfer((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchTransferEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ACTIVE');
})->with([
    'update' => 'encounter.encounters.update',
    'complete' => 'encounter.encounters.complete',
    'cancel' => 'encounter.encounters.cancel',
    'abandon' => 'encounter.encounters.abandon',
    'reopen' => 'encounter.encounters.reopen',
    'admit' => 'encounter.encounters.admit',
    'manage_disposition' => 'encounter.encounters.manage_disposition',
    'assign_location' => 'encounter.encounters.assign_location',
]);

test('per-subject facility scope: a caller not assigned at the encounter facility is denied (Invariant 24/25)', function () {
    ['tenant' => $tenant, 'user' => $user] = encounterTransferFixture(['encounter.encounters.transfer']);
    $encounterId = seedActiveEncounterForTransfer((string) $tenant->id, (string) Str::uuid7());

    expect(fn () => dispatchTransferEncounter((string) $tenant->id, null, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ACTIVE');
});

test('a missing / cross-tenant encounter is rejected with NotFound (tenant isolation, both directions)', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = encounterTransferFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = encounterTransferFixture();

    $encounterId = seedActiveEncounterForTransfer((string) $tenantA->id, (string) $facilityA->id);

    expect(fn () => dispatchTransferEncounter((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7(), (string) $userA->id))
        ->toThrow(NotFound::class);

    expect(fn () => dispatchTransferEncounter((string) $tenantB->id, (string) $facilityB->id, $encounterId, (string) $userB->id))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenantA->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ACTIVE');
});

// ── optimistic concurrency ───────────────────────────────────────────

test('a stale in-memory transfer() save throws ConcurrencyConflict — no partial mutation, no event, no audit', function () {
    ['tenant' => $tenant, 'facility' => $facility] = encounterTransferFixture();
    $encounterId = seedActiveEncounterForTransfer((string) $tenant->id, (string) $facility->id);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId) {
            $stale = Encounter::query()->findOrFail($encounterId); // version 0
            Encounter::query()->whereKey($encounterId)->update(['version' => 1]); // another writer bumps it

            $stale->transfer();
            app(EncounterRepository::class)->save($stale);
        });
        $this->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ACTIVE')->and($row->disposition)->toBeNull();
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterTransferred::class)->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.transferred')->count()))->toBe(0);
});

// ── active-attendance uniqueness — E.11 EXITS the predicate ───────────

test('ACTIVE -> TRANSFERRED leaves the active-attendance predicate — no uniqueness conflict, no uniqueness handling in the handler', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterTransferFixture([
        'encounter.encounters.transfer', 'encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.read',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
        'capability_code' => 'OUTPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Male])->id);

    $first = (string) Str::uuid7();
    $second = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($first, $second, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($first, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($first, (string) $user->id));
        $bus->dispatch(new TransferEncounter($first, (string) $user->id));
        // a second CreateEncounter for the same (patient, facility) must NOT hit ActiveEncounterExists
        $bus->dispatch(new CreateEncounter($second, $patientId, (string) $user->id, EncounterType::Outpatient));
    });

    $rows = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('patient_id', $patientId)->pluck('status', 'id'));
    expect($rows[$first])->toBe('TRANSFERRED')->and($rows[$second])->toBe('ARRIVED');

    $src = file_get_contents(base_path('app/Domains/Encounter/Application/Command/TransferEncounterHandler.php'));
    expect($src)->not->toContain('ActiveEncounterExists')
        ->and($src)->not->toContain('UniqueConstraintViolationException')
        ->and($src)->not->toContain('try {');
});

// ── cross-domain boundary — event only ───────────────────────────────

test('E.11 publishes EncounterTransferred to the outbox and does nothing else — no receiving encounter, no link row', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterTransferFixture();
    $patientId = (string) Str::uuid7();
    $encounterId = seedActiveEncounterForTransfer((string) $tenant->id, (string) $facility->id, ['patientId' => $patientId]);

    dispatchTransferEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    // exactly one EncounterTransferred, no synchronous receiving encounter created
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterTransferred::class)->count()))->toBe(1)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('patient_id', $patientId)->count()))->toBe(1);
});

// ── lifecycle compositions ───────────────────────────────────────────

test('lifecycle composition: CreateEncounter -> StartEncounter -> TransferEncounter reaches TRANSFERRED / TRANSFERRED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterTransferFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.transfer', 'patient.patients.read',
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
        $bus->dispatch(new TransferEncounter($encounterId, (string) $user->id));
    });

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('TRANSFERRED')
        ->and($row->disposition)->toBe('TRANSFERRED')
        ->and($row->arrived_at)->not->toBeNull()
        ->and($row->started_at)->not->toBeNull()
        ->and($row->completed_at)->toBeNull()
        ->and((int) $row->version)->toBe(2);
});

test('lifecycle composition: CreateEncounter(EMERGENCY) -> Start -> Admit -> Transfer reaches TRANSFERRED with admitted_at preserved, disposition overwritten from ADMITTED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterTransferFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.admit',
        'encounter.encounters.transfer', 'patient.patients.read',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        ['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id, 'capability_code' => 'EMERGENCY_CARE', 'state' => 'ENABLED', 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id, 'capability_code' => 'INPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]));

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Female])->id);

    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Emergency));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
        $bus->dispatch(new AdmitEncounter($encounterId, (string) $user->id));
        $bus->dispatch(new TransferEncounter($encounterId, (string) $user->id));
    });

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('TRANSFERRED')
        ->and($row->encounter_type)->toBe('INPATIENT')
        ->and($row->disposition)->toBe('TRANSFERRED')
        ->and($row->admitted_at)->not->toBeNull()
        ->and($row->completed_at)->toBeNull()
        ->and((int) $row->version)->toBe(3);
});

test('lifecycle composition: CreateEncounter -> Start -> ChangeDisposition(REFERRED) -> Transfer overwrites disposition to TRANSFERRED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterTransferFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.manage_disposition',
        'encounter.encounters.transfer', 'patient.patients.read',
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
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Referred));
        $bus->dispatch(new TransferEncounter($encounterId, (string) $user->id));
    });

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('TRANSFERRED')
        ->and($row->disposition)->toBe('TRANSFERRED')
        ->and((int) $row->version)->toBe(3); // Start, ChangeDisposition, Transfer
});

// ── E.8 / E.9 / E.10 cross-compatibility — TRANSFERRED is terminal ────

test('a TRANSFERRED encounter cannot be reopened, cancelled, or abandoned', function (string $command) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = encounterTransferFixture([
        'encounter.encounters.reopen', 'encounter.encounters.cancel', 'encounter.encounters.abandon',
    ]);
    $encounterId = seedEncounterWithStatusForTransfer((string) $tenant->id, (string) $facility->id, EncounterStatus::Transferred, [
        'attributes' => ['disposition' => EncounterDisposition::Transferred->value],
    ]);

    $dispatch = match ($command) {
        'reopen' => fn () => app(CommandBus::class)->dispatch(new ReopenEncounter($encounterId, (string) $user->id)),
        'cancel' => fn () => app(CommandBus::class)->dispatch(new CancelEncounter($encounterId, (string) $user->id)),
        'abandon' => fn () => app(CommandBus::class)->dispatch(new AbandonEncounter($encounterId, (string) $user->id)),
    };

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, $dispatch))
        ->toThrow(IllegalEncounterTransition::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('TRANSFERRED');
})->with(['reopen', 'cancel', 'abandon']);

// ── validation ───────────────────────────────────────────────────────

test('TransferEncounter rejects malformed ids at construction (IC §3)', function () {
    expect(fn () => new TransferEncounter('nope', (string) Str::uuid7()))->toThrow(InvalidArgumentException::class);
});
