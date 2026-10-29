<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\AdmitEncounter;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Contracts\Event\EncounterDispositionChanged;
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
 | PHASE 5 (increment E.5) gate — ChangeEncounterDisposition (record / revise the
 | disposition on an ACTIVE encounter, status stays ACTIVE) end-to-end through
 | the real CommandBus pipeline (IC §5): authorize (per-subject) -> lifecycle
 | guard (status + settable value) -> aggregate transition -> versioned save ->
 | outbox + audit, one transaction (11-encounter-domain.md §12, §76, §77, §79,
 | §101; ADR-121, ADR-130 A).
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function dispositionFixture(array $permissions = ['encounter.encounters.manage_disposition']): array
{
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser($permissions, []);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedActiveEncounterForDisposition(string $tenantId, string $facilityId, array $overrides = []): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient($overrides['patientId'] ?? (string) Str::uuid7())
        ->ofType($overrides['type'] ?? EncounterType::Outpatient)
        ->active()
        ->create($overrides['attributes'] ?? [])->id);
}

function dispatchChangeDisposition(string $tenantId, ?string $facilityId, string $encounterId, EncounterDisposition $disposition, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new ChangeEncounterDisposition($encounterId, $actorId, $disposition)));
}

// ── happy path ───────────────────────────────────────────────────────

test('ACTIVE encounter, null -> each settable disposition: disposition set, status stays ACTIVE, EncounterDispositionChanged on the outbox, audit written (metadata []), version bumped', function (EncounterDisposition $disposition) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = dispositionFixture();
    $patientId = (string) Str::uuid7();
    $encounterId = seedActiveEncounterForDisposition((string) $tenant->id, (string) $facility->id, ['patientId' => $patientId]);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($before->status)->toBe('ACTIVE')
        ->and($before->disposition)->toBeNull();

    dispatchChangeDisposition((string) $tenant->id, (string) $facility->id, $encounterId, $disposition, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ACTIVE')                       // unchanged
        ->and($row->disposition)->toBe($disposition->value)
        ->and((int) $row->version)->toBe(1)
        // transition timestamps / type / identity / location untouched
        ->and($row->encounter_type)->toBe($before->encounter_type)
        ->and($row->arrived_at)->toBe($before->arrived_at)
        ->and($row->started_at)->toBe($before->started_at)
        ->and($row->admitted_at)->toBeNull()
        ->and($row->completed_at)->toBeNull()
        ->and($row->patient_id)->toBe($before->patient_id)
        ->and($row->tenant_id)->toBe($before->tenant_id)
        ->and($row->facility_id)->toBe($before->facility_id)
        ->and($row->encounter_number)->toBe($before->encounter_number)
        ->and($row->department_id)->toBe($before->department_id)
        ->and($row->unit_id)->toBe($before->unit_id)
        ->and($row->created_at)->toBe($before->created_at)
        ->and($row->created_by_user_id)->toBe($before->created_by_user_id)
        ->and($row->created_source)->toBe($before->created_source);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterDispositionChanged::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterDispositionChanged')
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

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.disposition_changed')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('encounter')
        ->and($audit->target_id)->toBe($encounterId)
        ->and($audit->facility_id)->toBe((string) $facility->id)
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
})->with([
    'DISCHARGED' => [EncounterDisposition::Discharged],
    'REFERRED' => [EncounterDisposition::Referred],
    'LEFT' => [EncounterDisposition::Left],
    'FOLLOW_UP' => [EncounterDisposition::FollowUp],
    'DECEASED' => [EncounterDisposition::Deceased],
    'OTHER' => [EncounterDisposition::Other],
]);

test('it revises an existing disposition to a different value — one more event, version bumps again', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = dispositionFixture();
    $encounterId = seedActiveEncounterForDisposition((string) $tenant->id, (string) $facility->id);

    dispatchChangeDisposition((string) $tenant->id, (string) $facility->id, $encounterId, EncounterDisposition::Left, (string) $user->id);
    dispatchChangeDisposition((string) $tenant->id, (string) $facility->id, $encounterId, EncounterDisposition::Referred, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->disposition)->toBe('REFERRED')
        ->and($row->status)->toBe('ACTIVE')
        ->and((int) $row->version)->toBe(2);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterDispositionChanged::class)->count()))->toBe(2);
});

// ── inpatient discharge-preparation path (§79) ───────────────────────

test('AdmitEncounter then ChangeEncounterDisposition(DISCHARGED): type INPATIENT, disposition ADMITTED -> DISCHARGED, admitted_at unchanged, status ACTIVE', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = dispositionFixture([
        'encounter.encounters.admit', 'encounter.encounters.manage_disposition',
    ]);
    // an ACTIVE EMERGENCY encounter at an INPATIENT_CARE-enabled facility
    Tenancy::run((string) $tenant->id, fn () => DB::table('org_facility_capabilities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'facility_id' => (string) $facility->id,
        'capability_code' => 'INPATIENT_CARE', 'state' => 'ENABLED', 'enabled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $encounterId = seedActiveEncounterForDisposition((string) $tenant->id, (string) $facility->id, ['type' => EncounterType::Emergency]);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        app(CommandBus::class)->dispatch(new AdmitEncounter($encounterId, (string) $user->id));
    });

    $admitted = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($admitted->encounter_type)->toBe('INPATIENT')
        ->and($admitted->disposition)->toBe('ADMITTED')
        ->and($admitted->admitted_at)->not->toBeNull();

    dispatchChangeDisposition((string) $tenant->id, (string) $facility->id, $encounterId, EncounterDisposition::Discharged, (string) $user->id);

    $discharged = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($discharged->status)->toBe('ACTIVE')
        ->and($discharged->encounter_type)->toBe('INPATIENT')
        ->and($discharged->disposition)->toBe('DISCHARGED')
        ->and($discharged->admitted_at)->toBe($admitted->admitted_at)  // NOT re-stamped
        ->and((int) $discharged->version)->toBe(2);                    // admit (0->1), disposition (1->2)
});

// ── invalid source states ───────────────────────────────────────────

test('every source status except ACTIVE is rejected with IllegalEncounterTransition (409); no state / event / audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = dispositionFixture();

    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Active) {
            continue;
        }

        $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
            ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)
            ->forPatient((string) Str::uuid7())->ofType(EncounterType::Outpatient)->withStatus($status)->create()->id);

        try {
            dispatchChangeDisposition((string) $tenant->id, (string) $facility->id, $encounterId, EncounterDisposition::Discharged, (string) $user->id);
            test()->fail("expected IllegalEncounterTransition for source status {$status->value}");
        } catch (IllegalEncounterTransition $e) {
            expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
        }

        $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
        expect($row->status)->toBe($status->value)->and($row->disposition)->toBeNull()->and((int) $row->version)->toBe(0);
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterDispositionChanged::class)->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.disposition_changed')->count()))->toBe(0);
});

// ── non-settable values (ADR-130 A) ─────────────────────────────────

test('ADMITTED / TRANSFERRED are rejected as the requested disposition (409); no state / event / audit', function (EncounterDisposition $disposition) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = dispositionFixture();
    $encounterId = seedActiveEncounterForDisposition((string) $tenant->id, (string) $facility->id);

    try {
        dispatchChangeDisposition((string) $tenant->id, (string) $facility->id, $encounterId, $disposition, (string) $user->id);
        test()->fail("expected IllegalEncounterTransition for disposition {$disposition->value}");
    } catch (IllegalEncounterTransition $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_encounter_transition');
    }

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->disposition)->toBeNull()->and((int) $row->version)->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterDispositionChanged::class)->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.disposition_changed')->count()))->toBe(0);
})->with([
    'ADMITTED (owned by AdmitEncounter)' => [EncounterDisposition::Admitted],
    'TRANSFERRED (owned by TransferEncounter)' => [EncounterDisposition::Transferred],
]);

// ── same-value no-op ────────────────────────────────────────────────

test('setting the current disposition again is rejected (409) — no second event, version unchanged', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = dispositionFixture();
    $encounterId = seedActiveEncounterForDisposition((string) $tenant->id, (string) $facility->id);

    dispatchChangeDisposition((string) $tenant->id, (string) $facility->id, $encounterId, EncounterDisposition::FollowUp, (string) $user->id);

    expect(fn () => dispatchChangeDisposition((string) $tenant->id, (string) $facility->id, $encounterId, EncounterDisposition::FollowUp, (string) $user->id))
        ->toThrow(IllegalEncounterTransition::class);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->disposition)->toBe('FOLLOW_UP')->and((int) $row->version)->toBe(1);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterDispositionChanged::class)->count()))->toBe(1);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.disposition_changed')->count()))->toBe(1);
});

// ── authorization ───────────────────────────────────────────────────

test('it denies a caller without encounter.encounters.manage_disposition (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = dispositionFixture([]); // no keys
    $encounterId = seedActiveEncounterForDisposition((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchChangeDisposition((string) $tenant->id, (string) $facility->id, $encounterId, EncounterDisposition::Discharged, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('disposition')))->toBeNull();
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.disposition_changed')->count()))->toBe(0);
});

test('the encounter.encounters.update key does NOT authorize a disposition change — it needs its own dedicated key', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = dispositionFixture(['encounter.encounters.update']);
    $encounterId = seedActiveEncounterForDisposition((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchChangeDisposition((string) $tenant->id, (string) $facility->id, $encounterId, EncounterDisposition::Discharged, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('per-subject facility scope: a caller not assigned at the encounter facility is denied (Invariant 24/25)', function () {
    ['tenant' => $tenant, 'user' => $user] = dispositionFixture(['encounter.encounters.manage_disposition']);
    $encounterId = seedActiveEncounterForDisposition((string) $tenant->id, (string) Str::uuid7()); // a facility the user has no assignment for

    expect(fn () => dispatchChangeDisposition((string) $tenant->id, null, $encounterId, EncounterDisposition::Discharged, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('disposition')))->toBeNull();
});

// ── isolation / not found ───────────────────────────────────────────

test('a missing / cross-tenant encounter is rejected with NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = dispositionFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = dispositionFixture();

    $encounterId = seedActiveEncounterForDisposition((string) $tenantA->id, (string) $facilityA->id);

    expect(fn () => dispatchChangeDisposition((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7(), EncounterDisposition::Discharged, (string) $userA->id))
        ->toThrow(NotFound::class);

    expect(fn () => dispatchChangeDisposition((string) $tenantB->id, (string) $facilityB->id, $encounterId, EncounterDisposition::Discharged, (string) $userB->id))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenantA->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('disposition')))->toBeNull();
});

// ── optimistic concurrency (IC §9.1) ────────────────────────────────

test('a stale in-memory changeDisposition() save throws ConcurrencyConflict — no partial mutation, no event, no audit', function () {
    ['tenant' => $tenant, 'facility' => $facility] = dispositionFixture();
    $encounterId = seedActiveEncounterForDisposition((string) $tenant->id, (string) $facility->id);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId) {
            $stale = Encounter::query()->findOrFail($encounterId); // version 0
            Encounter::query()->whereKey($encounterId)->update(['version' => 1]); // another writer bumps it

            $stale->changeDisposition(EncounterDisposition::Discharged);
            app(EncounterRepository::class)->save($stale);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->disposition)->toBeNull();
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterDispositionChanged::class)->count()))->toBe(0);
});

// ── active-attendance uniqueness — E.5 does NOT use ActiveEncounterExists ──

test('ACTIVE -> ACTIVE disposition change never raises a uniqueness conflict — the handler has no uniqueness handling', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = dispositionFixture();
    $encounterId = seedActiveEncounterForDisposition((string) $tenant->id, (string) $facility->id);

    dispatchChangeDisposition((string) $tenant->id, (string) $facility->id, $encounterId, EncounterDisposition::Discharged, (string) $user->id);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('disposition')))->toBe('DISCHARGED');

    $src = file_get_contents(base_path('app/Domains/Encounter/Application/Command/ChangeEncounterDispositionHandler.php'));
    expect($src)->not->toContain('ActiveEncounterExists')
        ->and($src)->not->toContain('UniqueConstraintViolationException')
        ->and($src)->not->toContain('try {');
});

// ── composition ─────────────────────────────────────────────────────

test('CreateEncounter(OUTPATIENT) -> StartEncounter -> ChangeEncounterDisposition(REFERRED): disposition REFERRED, status ACTIVE, version 2', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = dispositionFixture([
        'encounter.encounters.create', 'encounter.encounters.update', 'encounter.encounters.manage_disposition', 'patient.patients.read',
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
        app(CommandBus::class)->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        app(CommandBus::class)->dispatch(new StartEncounter($encounterId, (string) $user->id));
        app(CommandBus::class)->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Referred));
    });

    $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($encounterId));
    expect($encounter->disposition)->toBe(EncounterDisposition::Referred)
        ->and($encounter->status)->toBe(EncounterStatus::Active)
        ->and((int) $encounter->version)->toBe(2); // Start (0->1), ChangeDisposition (1->2)
});

// ── validation ──────────────────────────────────────────────────────

test('ChangeEncounterDisposition rejects malformed ids at construction (IC §3)', function () {
    expect(fn () => new ChangeEncounterDisposition('nope', (string) Str::uuid7(), EncounterDisposition::Discharged))
        ->toThrow(InvalidArgumentException::class);
});
