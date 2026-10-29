<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\AdmitEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Contracts\Event\EncounterAdmitted;
use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Exception\EncounterTypeNotAvailable;
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
 | PHASE 5 (increment E.4) gate — AdmitEncounter (in-place OUTPATIENT/EMERGENCY ->
 | INPATIENT, status stays ACTIVE) end-to-end through the real CommandBus
 | pipeline (IC §5): authorize (per-subject) -> lifecycle guard -> INPATIENT_CARE
 | capability -> aggregate transition -> versioned save -> outbox + audit, one
 | transaction (11-encounter-domain.md §14.1, §78, §101; ADR-108, ADR-120,
 | ADR-121, ADR-124, ADR-130).
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @param  list<string>  $capabilities
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function admitFixture(
    array $permissions = ['encounter.encounters.admit'],
    array $capabilities = ['INPATIENT_CARE'],
): array {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser($permissions, $capabilities);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedActiveEncounter(string $tenantId, string $facilityId, array $overrides = []): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient($overrides['patientId'] ?? (string) Str::uuid7())
        ->ofType($overrides['type'] ?? EncounterType::Outpatient)
        ->active()
        ->create($overrides['attributes'] ?? [])->id);
}

function dispatchAdmitEncounter(string $tenantId, ?string $facilityId, string $encounterId, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new AdmitEncounter($encounterId, $actorId)));
}

// ── happy path ───────────────────────────────────────────────────────

test('ACTIVE OUTPATIENT|EMERGENCY -> INPATIENT: type + admitted_at + disposition set, status stays ACTIVE, EncounterAdmitted on the outbox, audit written, version bumped', function (EncounterType $sourceType) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = admitFixture();
    $patientId = (string) Str::uuid7();
    $encounterId = seedActiveEncounter((string) $tenant->id, (string) $facility->id, ['patientId' => $patientId, 'type' => $sourceType]);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($before->status)->toBe('ACTIVE')
        ->and($before->encounter_type)->toBe($sourceType->value)
        ->and($before->admitted_at)->toBeNull()
        ->and($before->disposition)->toBeNull();

    dispatchAdmitEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ACTIVE')                       // unchanged
        ->and($row->encounter_type)->toBe('INPATIENT')
        ->and($row->admitted_at)->not->toBeNull()
        ->and($row->disposition)->toBe('ADMITTED')
        ->and((int) $row->version)->toBe(1)
        // arrived_at / started_at NOT rewritten
        ->and($row->arrived_at)->toBe($before->arrived_at)
        ->and($row->started_at)->toBe($before->started_at)
        // immutable / untouched
        ->and($row->patient_id)->toBe($before->patient_id)
        ->and($row->tenant_id)->toBe($before->tenant_id)
        ->and($row->facility_id)->toBe($before->facility_id)
        ->and($row->encounter_number)->toBe($before->encounter_number)
        ->and($row->department_id)->toBe($before->department_id)
        ->and($row->unit_id)->toBe($before->unit_id)
        ->and($row->created_at)->toBe($before->created_at)
        ->and($row->created_by_user_id)->toBe($before->created_by_user_id)
        ->and($row->created_source)->toBe($before->created_source)
        ->and($row->completed_at)->toBeNull();

    $isFresh = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')
        ->where('id', $encounterId)->whereNotNull('admitted_at')
        ->where('admitted_at', '>=', now()->subMinutes(5))->where('admitted_at', '<=', now()->addMinutes(5))->exists());
    expect($isFresh)->toBeTrue();

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterAdmitted::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterAdmitted')
        ->and($outbox->published_at)->toBeNull();
    $payload = json_decode((string) $outbox->payload, true);
    expect(array_keys($payload))->toEqualCanonicalizing(['eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId', 'occurredAt', 'correlationId', 'causationId'])
        ->and($payload['encounterId'])->toBe($encounterId)
        ->and($payload['patientId'])->toBe($patientId)
        ->and($payload['facilityId'])->toBe((string) $facility->id);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.admitted')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('encounter')
        ->and($audit->target_id)->toBe($encounterId)
        ->and($audit->facility_id)->toBe((string) $facility->id)
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
})->with([
    'OUTPATIENT' => [EncounterType::Outpatient],
    'EMERGENCY' => [EncounterType::Emergency],
]);

// ── capability gating (ADR-120) ──────────────────────────────────────

test('INPATIENT_CARE not ENABLED -> EncounterTypeNotAvailable (409); no state / event / audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = admitFixture(capabilities: []); // no INPATIENT_CARE
    $encounterId = seedActiveEncounter((string) $tenant->id, (string) $facility->id);

    try {
        dispatchAdmitEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);
        test()->fail('expected EncounterTypeNotAvailable');
    } catch (EncounterTypeNotAvailable $e) {
        expect($e->httpStatus())->toBe(409)
            ->and($e->errorCode())->toBe('encounter_type_not_available');
    }

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->encounter_type)->toBe('OUTPATIENT')
        ->and($row->admitted_at)->toBeNull()
        ->and($row->disposition)->toBeNull()
        ->and((int) $row->version)->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterAdmitted::class)->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.admitted')->count()))->toBe(0);
});

// ── invalid source states / types (ADR-121, Invariant 15) ────────────

test('every source status except ACTIVE is rejected with IllegalEncounterTransition (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = admitFixture();

    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Active) {
            continue;
        }

        $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
            ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)
            ->forPatient((string) Str::uuid7())->ofType(EncounterType::Outpatient)->withStatus($status)->create()->id);

        try {
            dispatchAdmitEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);
            test()->fail("expected IllegalEncounterTransition for source status {$status->value}");
        } catch (IllegalEncounterTransition $e) {
            expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
        }

        $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
        expect($row->status)->toBe($status->value)->and((int) $row->version)->toBe(0);
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterAdmitted::class)->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.admitted')->count()))->toBe(0);
});

test('every non-admissible ACTIVE type is rejected with IllegalEncounterTransition (409)', function (EncounterType $type) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = admitFixture();
    $encounterId = seedActiveEncounter((string) $tenant->id, (string) $facility->id, ['type' => $type]);

    expect(fn () => dispatchAdmitEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(IllegalEncounterTransition::class);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->encounter_type)->toBe($type->value)->and((int) $row->version)->toBe(0);
})->with([
    'INPATIENT (already admitted — not idempotent)' => [EncounterType::Inpatient],
    'MATERNITY' => [EncounterType::Maternity],
    'DIAGNOSTIC' => [EncounterType::Diagnostic],
    'PROCEDURE' => [EncounterType::Procedure],
    'DAY_CARE' => [EncounterType::DayCare],
    'FOLLOW_UP' => [EncounterType::FollowUp],
    'TELEHEALTH' => [EncounterType::Telehealth],
    'REFERRAL' => [EncounterType::Referral],
    'OTHER' => [EncounterType::Other],
]);

test('an already-INPATIENT encounter is rejected — admitted_at not re-stamped, disposition unchanged', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = admitFixture();
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)
        ->forPatient((string) Str::uuid7())->ofType(EncounterType::Inpatient)->withStatus(EncounterStatus::Active)
        ->create(['admitted_at' => now()->subDay(), 'disposition' => EncounterDisposition::Admitted])->id);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());

    expect(fn () => dispatchAdmitEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(IllegalEncounterTransition::class);

    $after = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($after->admitted_at)->toBe($before->admitted_at)
        ->and($after->disposition)->toBe($before->disposition)
        ->and((int) $after->version)->toBe(0);
});

// ── authorization ────────────────────────────────────────────────────

test('it denies a caller without encounter.encounters.admit (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = admitFixture([]); // no keys
    $encounterId = seedActiveEncounter((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchAdmitEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('encounter_type')))->toBe('OUTPATIENT');
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.admitted')->count()))->toBe(0);
});

test('the encounter.encounters.update key does NOT authorize admission — admit needs its own dedicated key', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = admitFixture(['encounter.encounters.update']);
    $encounterId = seedActiveEncounter((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchAdmitEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('per-subject facility scope: a caller not assigned at the encounter facility is denied (Invariant 24/25)', function () {
    ['tenant' => $tenant, 'user' => $user] = admitFixture(['encounter.encounters.admit']);
    $encounterId = seedActiveEncounter((string) $tenant->id, (string) Str::uuid7()); // a facility the user has no assignment for

    expect(fn () => dispatchAdmitEncounter((string) $tenant->id, null, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('encounter_type')))->toBe('OUTPATIENT');
});

// ── isolation / not found ────────────────────────────────────────────

test('a missing / cross-tenant encounter is rejected with NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = admitFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = admitFixture();

    $encounterId = seedActiveEncounter((string) $tenantA->id, (string) $facilityA->id);

    expect(fn () => dispatchAdmitEncounter((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7(), (string) $userA->id))
        ->toThrow(NotFound::class);

    expect(fn () => dispatchAdmitEncounter((string) $tenantB->id, (string) $facilityB->id, $encounterId, (string) $userB->id))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenantA->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('encounter_type')))->toBe('OUTPATIENT');
});

// ── optimistic concurrency (IC §9.1, §144, §145) ────────────────────

test('a stale in-memory admit() save throws ConcurrencyConflict — no partial mutation, no event, no audit', function () {
    ['tenant' => $tenant, 'facility' => $facility] = admitFixture();
    $encounterId = seedActiveEncounter((string) $tenant->id, (string) $facility->id);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId) {
            $stale = Encounter::query()->findOrFail($encounterId); // version 0
            Encounter::query()->whereKey($encounterId)->update(['version' => 1]); // another writer bumps it

            $stale->admit();
            app(EncounterRepository::class)->save($stale);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->encounter_type)->toBe('OUTPATIENT')
        ->and($row->admitted_at)->toBeNull()
        ->and($row->disposition)->toBeNull();
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterAdmitted::class)->count()))->toBe(0);
});

// ── active-attendance uniqueness — E.4 does NOT use ActiveEncounterExists ──

test('ACTIVE -> ACTIVE admission never raises a uniqueness conflict — status and index key are unchanged', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = admitFixture();
    $encounterId = seedActiveEncounter((string) $tenant->id, (string) $facility->id);

    dispatchAdmitEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('encounter_type')))->toBe('INPATIENT');

    $src = file_get_contents(base_path('app/Domains/Encounter/Application/Command/AdmitEncounterHandler.php'));
    expect($src)->not->toContain('ActiveEncounterExists')
        ->and($src)->not->toContain('UniqueConstraintViolationException')
        ->and($src)->not->toContain('try {');
});

// ── composition ──────────────────────────────────────────────────────

test('CreateEncounter(EMERGENCY) -> StartEncounter -> AdmitEncounter reaches INPATIENT/ACTIVE with ordered arrived_at < started_at < admitted_at', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = admitFixture(
        ['encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.admit', 'patient.patients.read'],
        ['EMERGENCY_CARE', 'INPATIENT_CARE'],
    );

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Female])->id);

    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        app(CommandBus::class)->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Emergency));
        app(CommandBus::class)->dispatch(new StartEncounter($encounterId, (string) $user->id));
        app(CommandBus::class)->dispatch(new AdmitEncounter($encounterId, (string) $user->id));
    });

    $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($encounterId));
    expect($encounter->encounter_type)->toBe(EncounterType::Inpatient)
        ->and($encounter->status)->toBe(EncounterStatus::Active)
        ->and($encounter->disposition)->toBe(EncounterDisposition::Admitted)
        ->and($encounter->arrived_at)->not->toBeNull()
        ->and($encounter->started_at)->not->toBeNull()
        ->and($encounter->admitted_at)->not->toBeNull()
        ->and($encounter->started_at->greaterThanOrEqualTo($encounter->arrived_at))->toBeTrue()
        ->and($encounter->admitted_at->greaterThanOrEqualTo($encounter->started_at))->toBeTrue()
        ->and((int) $encounter->version)->toBe(2); // Start (0->1), Admit (1->2)
});

// ── validation ───────────────────────────────────────────────────────

test('AdmitEncounter rejects malformed ids at construction (IC §3)', function () {
    expect(fn () => new AdmitEncounter('nope', (string) Str::uuid7()))->toThrow(InvalidArgumentException::class);
});
