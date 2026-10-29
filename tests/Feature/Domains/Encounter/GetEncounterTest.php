<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Query\GetEncounter;
use App\Domains\Encounter\Contracts\Dto\EncounterSummary;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 (increment E.12) gate — GetEncounter through the real QueryBus /
 | #[ProtectedQuery] pipeline (IC §6.1, §6.2, §6.3; 11-encounter-domain.md §98,
 | §99, §102, §114; ADR-124, ADR-130). Backend query only — HTTP route is
 | PHASE 16. Read-only: no event, no outbox, no audit, no state change (R-3).
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function getEncounterFixture(array $permissions = ['encounter.encounters.read']): array
{
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser($permissions, []);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function seedEncounterForGet(string $tenantId, string $facilityId, EncounterStatus $status, array $attributes = []): string
{
    $arrivedAt = match ($status) {
        EncounterStatus::Planned => null,
        default => CarbonImmutable::now()->subMinutes(90),
    };
    $startedAt = match ($status) {
        EncounterStatus::Active, EncounterStatus::Completed, EncounterStatus::Abandoned, EncounterStatus::Transferred => CarbonImmutable::now()->subMinutes(60),
        default => null,
    };
    $completedAt = $status === EncounterStatus::Completed ? CarbonImmutable::now()->subMinutes(10) : null;
    $disposition = match ($status) {
        EncounterStatus::Completed => EncounterDisposition::Discharged->value,
        EncounterStatus::Transferred => EncounterDisposition::Transferred->value,
        default => null,
    };

    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient($attributes['patient_id'] ?? (string) Str::uuid7())
        ->ofType($attributes['encounter_type'] ?? EncounterType::Outpatient)
        ->withStatus($status)
        ->create(array_merge([
            'arrived_at' => $arrivedAt,
            'started_at' => $startedAt,
            'admitted_at' => null,
            'completed_at' => $completedAt,
            'disposition' => $disposition,
        ], $attributes))->id);
}

function dispatchGetEncounter(string $tenantId, ?string $facilityId, string $encounterId): EncounterSummary
{
    /** @var EncounterSummary */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch(new GetEncounter($encounterId)));
}

// ── happy path — every relevant lifecycle state ──────────────────────

test('returns the EncounterSummary for an encounter in state <status>', function (EncounterStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility] = getEncounterFixture();
    $patientId = (string) Str::uuid7();
    $departmentId = (string) Str::uuid7();
    $unitId = (string) Str::uuid7();
    $encounterId = seedEncounterForGet((string) $tenant->id, (string) $facility->id, $status, [
        'patient_id' => $patientId,
        'encounter_type' => EncounterType::Emergency,
        'department_id' => $departmentId,
        'unit_id' => $unitId,
        'created_source' => 'RECEPTION',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());

    $summary = dispatchGetEncounter((string) $tenant->id, (string) $facility->id, $encounterId);

    expect($summary)->toBeInstanceOf(EncounterSummary::class)
        ->and($summary->encounterId)->toBe($encounterId)
        ->and($summary->encounterNumber)->toBe($row->encounter_number)
        ->and($summary->patientId)->toBe($patientId)
        ->and($summary->facilityId)->toBe((string) $facility->id)
        ->and($summary->encounterType)->toBe('EMERGENCY')
        ->and($summary->status)->toBe($status->value)
        ->and($summary->departmentId)->toBe($departmentId)
        ->and($summary->unitId)->toBe($unitId)
        ->and($summary->createdSource)->toBe('RECEPTION');

    // disposition present only where the lifecycle sets one
    $expectedDisposition = match ($status) {
        EncounterStatus::Completed => 'DISCHARGED',
        EncounterStatus::Transferred => 'TRANSFERRED',
        default => null,
    };
    expect($summary->disposition)->toBe($expectedDisposition);

    // timestamps mirror the row exactly (nullable where the lifecycle permits)
    expect($summary->arrivedAt === null)->toBe($row->arrived_at === null)
        ->and($summary->startedAt === null)->toBe($row->started_at === null)
        ->and($summary->admittedAt)->toBeNull()
        ->and($summary->completedAt === null)->toBe($row->completed_at === null);
})->with([
    'ARRIVED' => EncounterStatus::Arrived,
    'ACTIVE' => EncounterStatus::Active,
    'COMPLETED' => EncounterStatus::Completed,
    'CANCELLED' => EncounterStatus::Cancelled,
    'ABANDONED' => EncounterStatus::Abandoned,
    'LEFT_WITHOUT_BEING_SEEN' => EncounterStatus::LeftWithoutBeingSeen,
    'TRANSFERRED' => EncounterStatus::Transferred,
]);

test('nullable fields are null on a freshly ARRIVED encounter with no location', function () {
    ['tenant' => $tenant, 'facility' => $facility] = getEncounterFixture();
    $encounterId = seedEncounterForGet((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived, [
        'department_id' => null,
        'unit_id' => null,
        'created_source' => null,
    ]);

    $summary = dispatchGetEncounter((string) $tenant->id, (string) $facility->id, $encounterId);

    expect($summary->disposition)->toBeNull()
        ->and($summary->departmentId)->toBeNull()
        ->and($summary->unitId)->toBeNull()
        ->and($summary->startedAt)->toBeNull()
        ->and($summary->admittedAt)->toBeNull()
        ->and($summary->completedAt)->toBeNull()
        ->and($summary->createdSource)->toBeNull()
        ->and($summary->arrivedAt)->not->toBeNull();
});

// ── authorization ───────────────────────────────────────────────────

test('a caller without encounter.encounters.read is denied (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facility] = getEncounterFixture([]);
    $encounterId = seedEncounterForGet((string) $tenant->id, (string) $facility->id, EncounterStatus::Active);

    expect(fn () => dispatchGetEncounter((string) $tenant->id, (string) $facility->id, $encounterId))
        ->toThrow(AuthorizationException::class);
});

test('a caller with encounter.encounters.read is allowed', function () {
    ['tenant' => $tenant, 'facility' => $facility] = getEncounterFixture(['encounter.encounters.read']);
    $encounterId = seedEncounterForGet((string) $tenant->id, (string) $facility->id, EncounterStatus::Active);

    expect(dispatchGetEncounter((string) $tenant->id, (string) $facility->id, $encounterId))
        ->toBeInstanceOf(EncounterSummary::class);
});

test('no sibling Encounter key authorizes GetEncounter — it needs its own encounter.encounters.read', function (string $siblingKey) {
    ['tenant' => $tenant, 'facility' => $facility] = getEncounterFixture([$siblingKey]);
    $encounterId = seedEncounterForGet((string) $tenant->id, (string) $facility->id, EncounterStatus::Active);

    expect(fn () => dispatchGetEncounter((string) $tenant->id, (string) $facility->id, $encounterId))
        ->toThrow(AuthorizationException::class);
})->with([
    'create' => 'encounter.encounters.create',
    'update' => 'encounter.encounters.update',
    'admit' => 'encounter.encounters.admit',
    'complete' => 'encounter.encounters.complete',
    'cancel' => 'encounter.encounters.cancel',
    'abandon' => 'encounter.encounters.abandon',
    'reopen' => 'encounter.encounters.reopen',
    'transfer' => 'encounter.encounters.transfer',
    'manage_disposition' => 'encounter.encounters.manage_disposition',
    'assign_location' => 'encounter.encounters.assign_location',
]);

test('dispatching GetEncounter with no tenant context is rejected (IC §6.1)', function () {
    ['tenant' => $tenant, 'facility' => $facility] = getEncounterFixture();
    $encounterId = seedEncounterForGet((string) $tenant->id, (string) $facility->id, EncounterStatus::Active);

    // no TenantContextScope wrapper — the QueryBus refuses to run
    expect(fn () => app(QueryBus::class)->dispatch(new GetEncounter($encounterId)))
        ->toThrow(RuntimeException::class);
});

// ── tenant isolation ────────────────────────────────────────────────

test('a tenant-B caller cannot read a tenant-A encounter (NotFound, RLS)', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA] = getEncounterFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = getEncounterFixture();

    $encounterId = seedEncounterForGet((string) $tenantA->id, (string) $facilityA->id, EncounterStatus::Active);

    test()->actingAs($userB);
    expect(fn () => dispatchGetEncounter((string) $tenantB->id, (string) $facilityB->id, $encounterId))
        ->toThrow(NotFound::class);
});

test('an unknown encounter id is NotFound (404)', function () {
    ['tenant' => $tenant, 'facility' => $facility] = getEncounterFixture();

    try {
        dispatchGetEncounter((string) $tenant->id, (string) $facility->id, (string) Str::uuid7());
        test()->fail('expected NotFound');
    } catch (NotFound $e) {
        expect($e->httpStatus())->toBe(404)->and($e->errorCode())->toBe('not_found');
    }
});

// ── facility isolation (R-1) ────────────────────────────────────────

test('a caller holding encounter.encounters.read at facility A cannot read an encounter at facility B — NotFound, not a disclosure', function () {
    ['tenant' => $tenant, 'facility' => $facility] = getEncounterFixture(['encounter.encounters.read']);
    // the caller's ACTIVE assignment (and read grant) is at the fixture facility;
    // the encounter belongs to a DIFFERENT facility in the same tenant.
    $otherFacilityId = (string) Str::uuid7();
    $encounterId = seedEncounterForGet((string) $tenant->id, $otherFacilityId, EncounterStatus::Active);

    try {
        // context = the caller's OWN facility, so the coarse #[ProtectedQuery] gate
        // passes (the actor holds the key there); the handler's per-encounter
        // refinement (R-1) then finds no read grant at the ENCOUNTER's facility.
        dispatchGetEncounter((string) $tenant->id, (string) $facility->id, $encounterId);
        test()->fail('expected NotFound (facility out of scope)');
    } catch (NotFound $e) {
        expect($e->httpStatus())->toBe(404);
    }
});

// ── PHI boundary ────────────────────────────────────────────────────

test('the EncounterSummary DTO exposes only identifiers / coded / lifecycle fields — no patient demographics or clinical data', function () {
    $fields = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(EncounterSummary::class, '__construct'))->getParameters(),
    );

    expect($fields)->toBe([
        'encounterId', 'encounterNumber', 'patientId', 'facilityId', 'encounterType',
        'status', 'disposition', 'departmentId', 'unitId',
        'arrivedAt', 'startedAt', 'admittedAt', 'completedAt', 'createdSource',
    ]);

    foreach (['name', 'displayName', 'dateOfBirth', 'dob', 'phone', 'email', 'address', 'diagnosis', 'clinical', 'bed', 'room', 'queue', 'careTeam'] as $forbidden) {
        expect(in_array($forbidden, $fields, true))->toBeFalse("DTO must not carry [{$forbidden}]");
    }

    // not a #[TypeScript] type — no PHASE 5 frontend consumer (ADR-124)
    $tsAttr = (new ReflectionClass(EncounterSummary::class))
        ->getAttributes(Spatie\TypeScriptTransformer\Attributes\TypeScript::class);
    expect($tsAttr)->toHaveCount(0);
});

// ── side effects (R-3) ──────────────────────────────────────────────

test('GetEncounter creates no event, no outbox row, no audit row and mutates no encounter state', function () {
    ['tenant' => $tenant, 'facility' => $facility] = getEncounterFixture();
    $encounterId = seedEncounterForGet((string) $tenant->id, (string) $facility->id, EncounterStatus::Completed);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    $outboxBefore = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count());
    $auditBefore = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->count());

    dispatchGetEncounter((string) $tenant->id, (string) $facility->id, $encounterId);
    dispatchGetEncounter((string) $tenant->id, (string) $facility->id, $encounterId); // twice — still no side effect

    $after = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());

    expect((int) $after->version)->toBe((int) $before->version)
        ->and($after->status)->toBe($before->status)
        ->and($after->disposition)->toBe($before->disposition)
        ->and($after->updated_at)->toBe($before->updated_at)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count()))->toBe($outboxBefore)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->count()))->toBe($auditBefore);
});
