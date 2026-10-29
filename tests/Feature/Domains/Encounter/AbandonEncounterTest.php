<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\AbandonEncounter;
use App\Domains\Encounter\Application\Command\AdmitEncounter;
use App\Domains\Encounter\Application\Command\CancelEncounter;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\ReopenEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Contracts\Event\EncounterAbandoned;
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
 | PHASE 5 (increment E.10) gate — AbandonEncounter
 | (ARRIVED -> LEFT_WITHOUT_BEING_SEEN | ACTIVE -> ABANDONED)
 | end-to-end through the CommandBus pipeline (IC §5): authorize (per-subject)
 | -> lifecycle guard (ARRIVED or ACTIVE only) -> aggregate transition -> versioned
 | save -> outbox + audit, one transaction (11-encounter-domain.md §19, §24, §76;
 | ADR-121, ADR-130 A/D).
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function abandonFixture(array $permissions = ['encounter.encounters.abandon']): array
{
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser($permissions, []);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedEncounterForAbandon(string $tenantId, string $facilityId, EncounterStatus $status, array $overrides = []): string
{
    $arrivedAt = match ($status) {
        EncounterStatus::Planned => null,
        default => CarbonImmutable::now()->subMinutes(60),
    };

    $startedAt = match ($status) {
        EncounterStatus::Active, EncounterStatus::Completed, EncounterStatus::Abandoned => CarbonImmutable::now()->subMinutes(30),
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
        ], $overrides['attributes'] ?? []))->id);
}

function dispatchAbandonEncounter(string $tenantId, ?string $facilityId, string $encounterId, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new AbandonEncounter($encounterId, $actorId)));
}

// ── happy path — ARRIVED -> LEFT_WITHOUT_BEING_SEEN ────────────────────

test('ARRIVED -> LEFT_WITHOUT_BEING_SEEN: status updated, preserved fields untouched, EncounterAbandoned on outbox, audit row, version bumped', function (EncounterType $type) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture();
    $patientId = (string) Str::uuid7();
    $encounterId = seedEncounterForAbandon((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived, [
        'patientId' => $patientId,
        'type' => $type,
        'attributes' => [
            'department_id' => (string) Str::uuid7(),
            'unit_id' => (string) Str::uuid7(),
        ],
    ]);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($before->status)->toBe('ARRIVED');

    dispatchAbandonEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('LEFT_WITHOUT_BEING_SEEN')
        ->and((int) $row->version)->toBe(1)
        // disposition untouched (ADR-130 A — neither required nor set)
        ->and($row->disposition)->toBeNull()
        // arrived_at preserved as historical record
        ->and($row->arrived_at)->toBe($before->arrived_at)
        // started_at, admitted_at, completed_at remain null
        ->and($row->started_at)->toBeNull()
        ->and($row->admitted_at)->toBeNull()
        ->and($row->completed_at)->toBeNull()
        // location and provenance preserved
        ->and($row->department_id)->toBe($before->department_id)
        ->and($row->unit_id)->toBe($before->unit_id)
        ->and($row->encounter_type)->toBe($type->value)
        ->and($row->encounter_number)->toBe($before->encounter_number)
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->facility_id)->toBe((string) $facility->id)
        ->and($row->created_by_user_id)->toBe($before->created_by_user_id)
        ->and($row->created_source)->toBe($before->created_source)
        ->and($row->created_at)->toBe($before->created_at);

    // outbox event written atomically
    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterAbandoned::class)
        ->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterAbandoned')
        ->and($outbox->tenant_id)->toBe((string) $tenant->id);

    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['encounterId'])->toBe($encounterId)
        ->and($payload['tenantId'])->toBe((string) $tenant->id)
        ->and($payload['facilityId'])->toBe((string) $facility->id)
        ->and($payload['patientId'])->toBe($patientId)
        ->and($payload['status'])->toBe('LEFT_WITHOUT_BEING_SEEN')
        ->and($payload)->toHaveKeys(['eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId', 'status', 'occurredAt', 'correlationId']);

    // audit row written
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'encounter.abandoned')
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
    'DIAGNOSTIC' => EncounterType::Diagnostic,
]);

// ── happy path — ACTIVE -> ABANDONED ──────────────────────────────────

test('ACTIVE -> ABANDONED: status updated, started_at and disposition preserved, EncounterAbandoned on outbox, audit row, version bumped', function (EncounterType $type) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture();
    $patientId = (string) Str::uuid7();
    $encounterId = seedEncounterForAbandon((string) $tenant->id, (string) $facility->id, EncounterStatus::Active, [
        'patientId' => $patientId,
        'type' => $type,
        'attributes' => [
            'department_id' => (string) Str::uuid7(),
            'unit_id' => (string) Str::uuid7(),
            'disposition' => EncounterDisposition::Left->value,
            'admitted_at' => $type === EncounterType::Inpatient ? CarbonImmutable::now()->subMinutes(15) : null,
        ],
    ]);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($before->status)->toBe('ACTIVE');

    dispatchAbandonEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ABANDONED')
        ->and((int) $row->version)->toBe(1)
        // disposition untouched / preserved
        ->and($row->disposition)->toBe(EncounterDisposition::Left->value)
        // arrived_at and started_at preserved as historical records
        ->and($row->arrived_at)->toBe($before->arrived_at)
        ->and($row->started_at)->toBe($before->started_at)
        ->and($row->admitted_at)->toBe($before->admitted_at)
        // completed_at remains null
        ->and($row->completed_at)->toBeNull()
        // location and provenance preserved
        ->and($row->department_id)->toBe($before->department_id)
        ->and($row->unit_id)->toBe($before->unit_id)
        ->and($row->encounter_type)->toBe($type->value)
        ->and($row->encounter_number)->toBe($before->encounter_number)
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->facility_id)->toBe((string) $facility->id);

    // outbox event carries status ABANDONED
    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterAbandoned::class)
        ->first());
    expect($outbox)->not->toBeNull();

    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['status'])->toBe('ABANDONED');
})->with([
    'OUTPATIENT' => EncounterType::Outpatient,
    'EMERGENCY' => EncounterType::Emergency,
    'INPATIENT' => EncounterType::Inpatient,
]);

// ── invalid source statuses ───────────────────────────────────────────

test('every source status except ARRIVED and ACTIVE is rejected with IllegalEncounterTransition (409)', function (EncounterStatus $invalidSource) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture();
    $encounterId = seedEncounterForAbandon((string) $tenant->id, (string) $facility->id, $invalidSource, [
        'attributes' => [
            'completed_at' => $invalidSource === EncounterStatus::Completed ? CarbonImmutable::now()->subMinutes(10) : null,
            'disposition' => $invalidSource === EncounterStatus::Completed ? EncounterDisposition::Discharged->value : null,
        ],
    ]);

    try {
        dispatchAbandonEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);
        $this->fail("expected IllegalEncounterTransition for {$invalidSource->value}");
    } catch (IllegalEncounterTransition $e) {
        expect($e->httpStatus())->toBe(409)
            ->and($e->errorCode())->toBe('illegal_encounter_transition');
    }

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe($invalidSource->value)
        ->and((int) $row->version)->toBe(0);

    // no outbox event or audit entry
    $outboxCount = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count());
    $auditCount = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->count());
    expect($outboxCount)->toBe(0)->and($auditCount)->toBe(0);
})->with([
    'PLANNED' => EncounterStatus::Planned,
    'COMPLETED' => EncounterStatus::Completed,
    'CANCELLED' => EncounterStatus::Cancelled,
    'LEFT_WITHOUT_BEING_SEEN' => EncounterStatus::LeftWithoutBeingSeen,
    'ABANDONED' => EncounterStatus::Abandoned,
    'TRANSFERRED' => EncounterStatus::Transferred,
    'REFERRED' => EncounterStatus::Referred,
]);

// ── non-idempotency ───────────────────────────────────────────────────

test('repeated abandonment fails on the second execution because destination statuses are terminal (ADR-121)', function (EncounterStatus $sourceStatus) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture();
    $encounterId = seedEncounterForAbandon((string) $tenant->id, (string) $facility->id, $sourceStatus);

    // first abandonment succeeds
    dispatchAbandonEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $terminalStatus = $sourceStatus === EncounterStatus::Arrived ? 'LEFT_WITHOUT_BEING_SEEN' : 'ABANDONED';
    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe($terminalStatus)
        ->and((int) $row->version)->toBe(1);

    // second abandonment fails with 409
    expect(fn () => dispatchAbandonEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(IllegalEncounterTransition::class);

    $rowAfter = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($rowAfter->status)->toBe($terminalStatus)
        ->and((int) $rowAfter->version)->toBe(1);
})->with([
    'ARRIVED' => EncounterStatus::Arrived,
    'ACTIVE' => EncounterStatus::Active,
]);

// ── authorization ─────────────────────────────────────────────────────

test('it denies a caller without encounter.encounters.abandon (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture([]);
    $encounterId = seedEncounterForAbandon((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);

    expect(fn () => dispatchAbandonEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('the encounter.encounters.update key does NOT authorize abandon', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture(['encounter.encounters.update']);
    $encounterId = seedEncounterForAbandon((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);

    expect(fn () => dispatchAbandonEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('the encounter.encounters.cancel key does NOT authorize abandon', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture(['encounter.encounters.cancel']);
    $encounterId = seedEncounterForAbandon((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);

    expect(fn () => dispatchAbandonEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('the encounter.encounters.complete key does NOT authorize abandon', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture(['encounter.encounters.complete']);
    $encounterId = seedEncounterForAbandon((string) $tenant->id, (string) $facility->id, EncounterStatus::Active);

    expect(fn () => dispatchAbandonEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('the encounter.encounters.reopen key does NOT authorize abandon', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture(['encounter.encounters.reopen']);
    $encounterId = seedEncounterForAbandon((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);

    expect(fn () => dispatchAbandonEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('per-subject facility scope: a caller not assigned at the encounter facility is denied (Invariant 24/25)', function () {
    ['tenant' => $tenant, 'user' => $user] = abandonFixture(['encounter.encounters.abandon']);
    $encounterId = seedEncounterForAbandon((string) $tenant->id, (string) Str::uuid7(), EncounterStatus::Arrived);

    expect(fn () => dispatchAbandonEncounter((string) $tenant->id, null, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ARRIVED');
});

test('a missing / cross-tenant encounter is rejected with NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = abandonFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = abandonFixture();

    $encounterId = seedEncounterForAbandon((string) $tenantA->id, (string) $facilityA->id, EncounterStatus::Arrived);

    expect(fn () => dispatchAbandonEncounter((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7(), (string) $userA->id))
        ->toThrow(NotFound::class);

    expect(fn () => dispatchAbandonEncounter((string) $tenantB->id, (string) $facilityB->id, $encounterId, (string) $userB->id))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenantA->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ARRIVED');
});

// ── optimistic concurrency ───────────────────────────────────────────

test('a stale in-memory abandon() save throws ConcurrencyConflict — no partial mutations', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture();
    $encounterId = seedEncounterForAbandon((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId) {
        $repo = app(EncounterRepository::class);
        $encounter1 = $repo->getById($encounterId);
        $encounter2 = $repo->getById($encounterId);

        $encounter1->abandon(EncounterStatus::LeftWithoutBeingSeen);
        $repo->save($encounter1);

        $encounter2->abandon(EncounterStatus::LeftWithoutBeingSeen);
        expect(fn () => $repo->save($encounter2))->toThrow(ConcurrencyConflict::class);
    });

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect((int) $row->version)->toBe(1);

    // only one event was written
    $eventCount = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count());
    expect($eventCount)->toBe(1);
});

// ── active-attendance uniqueness predicate exit ───────────────────────

test('abandoning an ARRIVED or ACTIVE encounter exits the active-attendance predicate, freeing the slot', function (EncounterStatus $sourceStatus) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture([
        'encounter.encounters.abandon', 'encounter.encounters.create', 'patient.patients.read',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
        'capability_code' => 'OUTPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Female])->id);

    $firstEncounterId = seedEncounterForAbandon((string) $tenant->id, (string) $facility->id, $sourceStatus, [
        'patientId' => $patientId,
    ]);

    // abandon first encounter -> exits active index predicate
    dispatchAbandonEncounter((string) $tenant->id, (string) $facility->id, $firstEncounterId, (string) $user->id);

    // creating a second encounter for the same patient at the same facility succeeds
    $secondEncounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($secondEncounterId, $patientId, $user) {
        app(CommandBus::class)->dispatch(new CreateEncounter(
            $secondEncounterId,
            $patientId,
            (string) $user->id,
            EncounterType::Outpatient,
        ));
    });

    $secondRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $secondEncounterId)->first());
    expect($secondRow->status)->toBe('ARRIVED');
})->with([
    'ARRIVED' => EncounterStatus::Arrived,
    'ACTIVE' => EncounterStatus::Active,
]);

// ── lifecycle compositions ────────────────────────────────────────────

test('lifecycle composition: CreateEncounter(walk-in) -> AbandonEncounter reaches LEFT_WITHOUT_BEING_SEEN', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture([
        'encounter.encounters.create', 'encounter.encounters.abandon', 'patient.patients.read',
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
        $bus->dispatch(new AbandonEncounter($encounterId, (string) $user->id));
    });

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('LEFT_WITHOUT_BEING_SEEN')
        ->and($row->arrived_at)->not->toBeNull()
        ->and($row->started_at)->toBeNull()
        ->and((int) $row->version)->toBe(1);
});

test('lifecycle composition: CreateEncounter -> StartEncounter -> AbandonEncounter reaches ABANDONED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.abandon', 'patient.patients.read',
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
        $bus->dispatch(new AbandonEncounter($encounterId, (string) $user->id));
    });

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ABANDONED')
        ->and($row->arrived_at)->not->toBeNull()
        ->and($row->started_at)->not->toBeNull()
        ->and((int) $row->version)->toBe(2);
});

test('lifecycle composition: CreateEncounter(EMERGENCY) -> Start -> Admit -> Abandon reaches ABANDONED with admitted_at preserved', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.admit',
        'encounter.encounters.abandon', 'patient.patients.read',
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
        $bus->dispatch(new AbandonEncounter($encounterId, (string) $user->id));
    });

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ABANDONED')
        ->and($row->encounter_type)->toBe('INPATIENT')
        ->and($row->disposition)->toBe('ADMITTED')
        ->and($row->admitted_at)->not->toBeNull()
        ->and((int) $row->version)->toBe(3);
});

// ── E.8 & E.9 cross-compatibility ─────────────────────────────────────

test('E.8 compatibility: Complete -> Reopen -> Abandon reaches ABANDONED (reopened ACTIVE encounter may be abandoned)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.manage_disposition',
        'encounter.encounters.complete', 'encounter.encounters.reopen', 'encounter.encounters.abandon', 'patient.patients.read',
    ]);
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
        'capability_code' => 'OUTPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Female])->id);

    $encounterId = (string) Str::uuid7();

    // 1. Complete an encounter
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    $completedRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($completedRow->status)->toBe('COMPLETED');

    // 2. Reopen the encounter -> ACTIVE
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        app(CommandBus::class)->dispatch(new ReopenEncounter($encounterId, (string) $user->id));
    });

    $reopenedRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($reopenedRow->status)->toBe('ACTIVE');

    // 3. Abandon the reopened ACTIVE encounter -> ABANDONED
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        app(CommandBus::class)->dispatch(new AbandonEncounter($encounterId, (string) $user->id));
    });

    $finalRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($finalRow->status)->toBe('ABANDONED')
        ->and($finalRow->completed_at)->toBe($completedRow->completed_at);
});

test('E.8 compatibility: Abandoned encounters cannot be reopened (assertCanReopen allows only COMPLETED)', function (EncounterStatus $abandonedStatus) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture([
        'encounter.encounters.reopen',
    ]);
    $encounterId = seedEncounterForAbandon((string) $tenant->id, (string) $facility->id, $abandonedStatus);

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new ReopenEncounter($encounterId, (string) $user->id))))
        ->toThrow(IllegalEncounterTransition::class);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe($abandonedStatus->value);
})->with([
    'LEFT_WITHOUT_BEING_SEEN' => EncounterStatus::LeftWithoutBeingSeen,
    'ABANDONED' => EncounterStatus::Abandoned,
]);

test('E.9 compatibility: Abandoned encounters cannot be cancelled (assertCanCancel allows only PLANNED and ARRIVED)', function (EncounterStatus $abandonedStatus) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = abandonFixture([
        'encounter.encounters.cancel',
    ]);
    $encounterId = seedEncounterForAbandon((string) $tenant->id, (string) $facility->id, $abandonedStatus);

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new CancelEncounter($encounterId, (string) $user->id))))
        ->toThrow(IllegalEncounterTransition::class);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe($abandonedStatus->value);
})->with([
    'LEFT_WITHOUT_BEING_SEEN' => EncounterStatus::LeftWithoutBeingSeen,
    'ABANDONED' => EncounterStatus::Abandoned,
]);
