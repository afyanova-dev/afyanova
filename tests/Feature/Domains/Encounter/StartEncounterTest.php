<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\RecordArrival;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Contracts\Event\EncounterStarted;
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
 | PHASE 5 (increment E.3) gate — StartEncounter (ARRIVED -> ACTIVE) end-to-end
 | through the real CommandBus pipeline (IC §5): authorize (per-subject) ->
 | lifecycle guard -> aggregate transition -> versioned save -> outbox + audit,
 | one transaction (11-encounter-domain.md §19, §31, §101; ADR-121, ADR-124,
 | ADR-130).
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @param  list<string>  $capabilities
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function startFixture(array $permissions = ['encounter.encounters.update'], array $capabilities = []): array
{
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser($permissions, $capabilities);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedArrivedEncounter(string $tenantId, string $facilityId, array $overrides = []): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient($overrides['patientId'] ?? (string) Str::uuid7())
        ->arrived()
        ->create($overrides['attributes'] ?? [])->id);
}

function dispatchStartEncounter(string $tenantId, ?string $facilityId, string $encounterId, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new StartEncounter($encounterId, $actorId)));
}

// ── happy path ───────────────────────────────────────────────────────

test('ARRIVED -> ACTIVE: status + started_at set, arrived_at unchanged, EncounterStarted on the outbox, audit written, version bumped', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = startFixture();
    $patientId = (string) Str::uuid7();
    $encounterId = seedArrivedEncounter((string) $tenant->id, (string) $facility->id, ['patientId' => $patientId]);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($before->status)->toBe('ARRIVED')
        ->and($before->arrived_at)->not->toBeNull()
        ->and($before->started_at)->toBeNull();

    dispatchStartEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->status)->toBe('ACTIVE')
        ->and($row->started_at)->not->toBeNull()
        ->and((int) $row->version)->toBe(1)
        // arrived_at NOT rewritten
        ->and($row->arrived_at)->toBe($before->arrived_at)
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
        ->and($row->admitted_at)->toBeNull()
        ->and($row->completed_at)->toBeNull();

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterStarted::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterStarted')
        ->and($outbox->published_at)->toBeNull();
    $payload = json_decode((string) $outbox->payload, true);
    expect(array_keys($payload))->toEqualCanonicalizing(['eventId', 'tenantId', 'facilityId', 'encounterId', 'patientId', 'occurredAt', 'correlationId', 'causationId'])
        ->and($payload['encounterId'])->toBe($encounterId)
        ->and($payload['patientId'])->toBe($patientId)
        ->and($payload['facilityId'])->toBe((string) $facility->id);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.started')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('encounter')
        ->and($audit->target_id)->toBe($encounterId)
        ->and($audit->facility_id)->toBe((string) $facility->id)
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('started_at is server-generated (a fresh timestamp) and the command exposes no way to supply one', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = startFixture();
    $encounterId = seedArrivedEncounter((string) $tenant->id, (string) $facility->id);

    // null before
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('started_at')))->toBeNull();

    dispatchStartEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    // set, and to a fresh server instant (DB-side, tz-aware comparison)
    $isFresh = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')
        ->where('id', $encounterId)
        ->whereNotNull('started_at')
        ->where('started_at', '>=', now()->subMinutes(5))
        ->where('started_at', '<=', now()->addMinutes(5))
        ->exists());
    expect($isFresh)->toBeTrue();

    // the command has NO timestamp parameter — a caller cannot influence started_at
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(StartEncounter::class, '__construct'))->getParameters(),
    );
    expect($params)->toBe(['encounterId', 'actorId']);
});

// ── invalid source states (ADR-121, Invariant 15) ────────────────────

test('every source status except ARRIVED is rejected with IllegalEncounterTransition (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = startFixture();

    foreach (EncounterStatus::cases() as $status) {
        if ($status === EncounterStatus::Arrived) {
            continue;
        }

        $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
            ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)
            ->forPatient((string) Str::uuid7())->withStatus($status)->create()->id);

        try {
            dispatchStartEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);
            test()->fail("expected IllegalEncounterTransition for source status {$status->value}");
        } catch (IllegalEncounterTransition $e) {
            expect($e->httpStatus())->toBe(409)
                ->and($e->errorCode())->toBe('illegal_encounter_transition');
        }

        $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
        expect($row->status)->toBe($status->value)
            ->and((int) $row->version)->toBe(0);
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterStarted::class)->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.started')->count()))->toBe(0);
});

test('an already-ACTIVE encounter is rejected — StartEncounter is not idempotent', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = startFixture();
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)
        ->forPatient((string) Str::uuid7())->withStatus(EncounterStatus::Active)
        ->create(['started_at' => now()->subHour()])->id);

    $startedAtBefore = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('started_at'));

    expect(fn () => dispatchStartEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(IllegalEncounterTransition::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('started_at')))
        ->toBe($startedAtBefore); // not re-stamped
});

// ── authorization ────────────────────────────────────────────────────

test('it denies a caller without encounter.encounters.update (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = startFixture([]); // no keys
    $encounterId = seedArrivedEncounter((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchStartEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ARRIVED');
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'encounter.started')->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', EncounterStarted::class)->count()))->toBe(0);
});

test('per-subject facility scope: a caller not assigned at the encounter facility is denied (Invariant 24/25)', function () {
    ['tenant' => $tenant, 'user' => $user] = startFixture(['encounter.encounters.update']);
    $encounterId = seedArrivedEncounter((string) $tenant->id, (string) Str::uuid7()); // a facility the user has no assignment for

    expect(fn () => dispatchStartEncounter((string) $tenant->id, null, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ARRIVED');
});

// ── isolation / not found ────────────────────────────────────────────

test('a missing / cross-tenant encounter is rejected with NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = startFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = startFixture();

    $encounterId = seedArrivedEncounter((string) $tenantA->id, (string) $facilityA->id);

    expect(fn () => dispatchStartEncounter((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7(), (string) $userA->id))
        ->toThrow(NotFound::class);

    expect(fn () => dispatchStartEncounter((string) $tenantB->id, (string) $facilityB->id, $encounterId, (string) $userB->id))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenantA->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ARRIVED');
});

// ── optimistic concurrency (IC §9.1, §144, §145) ────────────────────

test('a stale in-memory start() save throws ConcurrencyConflict', function () {
    ['tenant' => $tenant, 'facility' => $facility] = startFixture();
    $encounterId = seedArrivedEncounter((string) $tenant->id, (string) $facility->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId) {
        $stale = Encounter::query()->findOrFail($encounterId); // version 0
        Encounter::query()->whereKey($encounterId)->update(['version' => 1]); // another writer bumps it

        $stale->start();
        app(EncounterRepository::class)->save($stale);
    });
})->throws(ConcurrencyConflict::class);

// ── composition ──────────────────────────────────────────────────────

test('CreateEncounter(planned) -> RecordArrival -> StartEncounter reaches ACTIVE with distinct arrived_at / started_at', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = startFixture(
        ['encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.read'],
        ['OUTPATIENT_CARE'],
    );

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)->create(['sex' => Sex::Female])->id);

    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        app(CommandBus::class)->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient, planned: true));
        app(CommandBus::class)->dispatch(new RecordArrival($encounterId, (string) $user->id));
        app(CommandBus::class)->dispatch(new StartEncounter($encounterId, (string) $user->id));
    });

    $encounter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Encounter::query()->find($encounterId));
    expect($encounter->status)->toBe(EncounterStatus::Active)
        ->and($encounter->arrived_at)->not->toBeNull()
        ->and($encounter->started_at)->not->toBeNull()
        ->and($encounter->started_at->greaterThanOrEqualTo($encounter->arrived_at))->toBeTrue()
        ->and((int) $encounter->version)->toBe(2); // RecordArrival (0->1), StartEncounter (1->2)
});

// ── active-attendance uniqueness — E.3 does NOT use ActiveEncounterExists ──

test('ARRIVED -> ACTIVE never raises a uniqueness conflict — both statuses are inside the partial index with an unchanged key', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = startFixture();
    $encounterId = seedArrivedEncounter((string) $tenant->id, (string) $facility->id);

    // The patient cannot already have a second ARRIVED/ACTIVE encounter here —
    // enc_one_active_per_patient_facility already forbids it — so ARRIVED -> ACTIVE
    // updates the existing index entry in place and cannot collide.
    dispatchStartEncounter((string) $tenant->id, (string) $facility->id, $encounterId, (string) $user->id);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('ACTIVE');

    // StartEncounterHandler imports no ActiveEncounterExists / UniqueConstraintViolationException
    $src = file_get_contents(base_path('app/Domains/Encounter/Application/Command/StartEncounterHandler.php'));
    expect($src)->not->toContain('ActiveEncounterExists')
        ->and($src)->not->toContain('UniqueConstraintViolationException');
});

// ── validation ───────────────────────────────────────────────────────

test('StartEncounter rejects malformed ids at construction (IC §3)', function () {
    expect(fn () => new StartEncounter('nope', (string) Str::uuid7()))->toThrow(InvalidArgumentException::class);
});
