<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Encounter;

use App\Domains\Encounter\Application\Query\ListFacilityEncounters;
use App\Domains\Encounter\Contracts\Dto\EncounterSummary;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/*
 | Increment A (E.13) feature gate — ListFacilityEncounters through QueryBus /
 | #[ProtectedQuery] pipeline (IC §6.1, §6.2, §6.3; 11-encounter-domain.md §99,
 | §102, §114; ADR-130).
 | Read-only: no event, no outbox, no audit, no state change.
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function listEncounterFixture(array $permissions = ['encounter.encounters.read']): array
{
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser($permissions, []);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function seedEncounterForList(
    string $tenantId,
    string $facilityId,
    EncounterStatus $status,
    ?CarbonImmutable $arrivedAt = null,
    array $attributes = [],
): string {
    $arrivedAt = $arrivedAt ?? match ($status) {
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

/**
 * @param  list<EncounterStatus|string>|null  $statuses
 * @return list<EncounterSummary>
 */
function dispatchListFacilityEncounters(
    string $tenantId,
    ?string $facilityId,
    string $targetFacilityId,
    CarbonImmutable|string|null $date = null,
    ?array $statuses = null,
    int $limit = 100,
): array {
    return TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => app(QueryBus::class)->dispatch(
            new ListFacilityEncounters(
                facilityId: $targetFacilityId,
                date: $date,
                statuses: $statuses,
                limit: $limit,
            )
        )
    );
}

// ── happy path — collection reading & filtering ──────────────────────

test('returns list of EncounterSummary for encounters at the facility', function () {
    ['tenant' => $tenant, 'facility' => $facility] = listEncounterFixture();

    $id1 = seedEncounterForList((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);
    $id2 = seedEncounterForList((string) $tenant->id, (string) $facility->id, EncounterStatus::Active);

    $results = dispatchListFacilityEncounters((string) $tenant->id, (string) $facility->id, (string) $facility->id);

    expect($results)->toBeArray()
        ->and($results)->toHaveCount(2);

    $ids = array_map(static fn (EncounterSummary $s): string => $s->encounterId, $results);
    expect($ids)->toContain($id1, $id2);
    expect($results[0])->toBeInstanceOf(EncounterSummary::class);
});

test('filters encounters by date', function () {
    ['tenant' => $tenant, 'facility' => $facility] = listEncounterFixture();

    $today = CarbonImmutable::now();
    $yesterday = $today->subDays(1);

    $todayEncounterId = seedEncounterForList(
        (string) $tenant->id,
        (string) $facility->id,
        EncounterStatus::Arrived,
        $today->subHours(2),
    );

    $yesterdayEncounterId = seedEncounterForList(
        (string) $tenant->id,
        (string) $facility->id,
        EncounterStatus::Arrived,
        $yesterday->subHours(2),
    );

    $todayResults = dispatchListFacilityEncounters(
        tenantId: (string) $tenant->id,
        facilityId: (string) $facility->id,
        targetFacilityId: (string) $facility->id,
        date: $today,
    );

    $todayIds = array_map(static fn (EncounterSummary $s): string => $s->encounterId, $todayResults);
    expect($todayIds)->toContain($todayEncounterId)
        ->and($todayIds)->not->toContain($yesterdayEncounterId);
});

test('filters encounters by status', function () {
    ['tenant' => $tenant, 'facility' => $facility] = listEncounterFixture();

    $arrivedId = seedEncounterForList((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);
    $completedId = seedEncounterForList((string) $tenant->id, (string) $facility->id, EncounterStatus::Completed);

    $results = dispatchListFacilityEncounters(
        tenantId: (string) $tenant->id,
        facilityId: (string) $facility->id,
        targetFacilityId: (string) $facility->id,
        statuses: [EncounterStatus::Arrived],
    );

    $ids = array_map(static fn (EncounterSummary $s): string => $s->encounterId, $results);
    expect($ids)->toContain($arrivedId)
        ->and($ids)->not->toContain($completedId);
});

test('returns empty array when authorized but no encounters match', function () {
    ['tenant' => $tenant, 'facility' => $facility] = listEncounterFixture();

    $results = dispatchListFacilityEncounters(
        tenantId: (string) $tenant->id,
        facilityId: (string) $facility->id,
        targetFacilityId: (string) $facility->id,
        date: CarbonImmutable::now()->subYears(2),
    );

    expect($results)->toBeArray()->toBeEmpty();
});

test('respects limit parameter', function () {
    ['tenant' => $tenant, 'facility' => $facility] = listEncounterFixture();

    seedEncounterForList((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);
    seedEncounterForList((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);
    seedEncounterForList((string) $tenant->id, (string) $facility->id, EncounterStatus::Arrived);

    $results = dispatchListFacilityEncounters(
        tenantId: (string) $tenant->id,
        facilityId: (string) $facility->id,
        targetFacilityId: (string) $facility->id,
        limit: 2,
    );

    expect($results)->toHaveCount(2);
});

// ── authorization & facility isolation ───────────────────────────────

test('a caller without encounter.encounters.read throws AuthorizationException', function () {
    ['tenant' => $tenant, 'facility' => $facility] = listEncounterFixture([]);

    expect(fn () => dispatchListFacilityEncounters(
        (string) $tenant->id,
        (string) $facility->id,
        (string) $facility->id,
    ))->toThrow(AuthorizationException::class);
});

test('a caller holding encounter.encounters.read at facility A cannot list encounters at facility B (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facilityA] = listEncounterFixture(['encounter.encounters.read']);
    $facilityBId = (string) Str::uuid7();

    // Caller is scoped at facility A, but attempts to query facility B
    expect(fn () => dispatchListFacilityEncounters(
        tenantId: (string) $tenant->id,
        facilityId: (string) $facilityA->id,
        targetFacilityId: $facilityBId,
    ))->toThrow(AuthorizationException::class);
});

test('sibling encounter permissions do not authorize listing', function (string $siblingKey) {
    ['tenant' => $tenant, 'facility' => $facility] = listEncounterFixture([$siblingKey]);

    expect(fn () => dispatchListFacilityEncounters(
        (string) $tenant->id,
        (string) $facility->id,
        (string) $facility->id,
    ))->toThrow(AuthorizationException::class);
})->with([
    'create' => 'encounter.encounters.create',
    'update' => 'encounter.encounters.update',
    'admit' => 'encounter.encounters.admit',
    'complete' => 'encounter.encounters.complete',
    'cancel' => 'encounter.encounters.cancel',
    'abandon' => 'encounter.encounters.abandon',
    'reopen' => 'encounter.encounters.reopen',
    'transfer' => 'encounter.encounters.transfer',
]);

test('dispatching with no tenant context throws RuntimeException', function () {
    ['tenant' => $tenant, 'facility' => $facility] = listEncounterFixture();

    expect(fn () => app(QueryBus::class)->dispatch(new ListFacilityEncounters((string) $facility->id)))
        ->toThrow(RuntimeException::class);
});

// ── tenant isolation (RLS) ──────────────────────────────────────────

test('tenant B caller cannot list tenant A encounters even if target facility is provided', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA] = listEncounterFixture(['encounter.encounters.read']);
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = listEncounterFixture(['encounter.encounters.read']);

    seedEncounterForList((string) $tenantA->id, (string) $facilityA->id, EncounterStatus::Arrived);

    test()->actingAs($userB);

    // Tenant B actor querying Facility A throws AuthorizationException because userB has no assignment at Facility A
    expect(fn () => dispatchListFacilityEncounters(
        tenantId: (string) $tenantB->id,
        facilityId: (string) $facilityB->id,
        targetFacilityId: (string) $facilityA->id,
    ))->toThrow(AuthorizationException::class);
});

// ── read-only & side-effects ─────────────────────────────────────────

test('ListFacilityEncounters creates no events, no outbox row, no audit row, and mutates no state', function () {
    ['tenant' => $tenant, 'facility' => $facility] = listEncounterFixture();
    $encounterId = seedEncounterForList((string) $tenant->id, (string) $facility->id, EncounterStatus::Active);

    $outboxBefore = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count());
    $auditBefore = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->count());
    $beforeRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());

    dispatchListFacilityEncounters((string) $tenant->id, (string) $facility->id, (string) $facility->id);
    dispatchListFacilityEncounters((string) $tenant->id, (string) $facility->id, (string) $facility->id);

    $afterRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    $outboxAfter = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count());
    $auditAfter = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->count());

    expect($outboxAfter)->toBe($outboxBefore)
        ->and($auditAfter)->toBe($auditBefore)
        ->and($afterRow->updated_at)->toBe($beforeRow->updated_at)
        ->and($afterRow->version)->toBe($beforeRow->version);
});
