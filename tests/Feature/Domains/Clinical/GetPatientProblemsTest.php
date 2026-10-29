<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetPatientProblems;
use App\Domains\Clinical\Contracts\Dto\ProblemSummary;
use App\Domains\Clinical\Domain\Model\Problem;
use App\Domains\Clinical\Domain\ValueObject\ProblemStatus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.4) gate — GetPatientProblems through the real QueryBus (IC §6.1,
 | §6.2; ADR-140 D-L / clarification lock Decision 3). One patient's list,
 | optional status filter, deterministic order, NO pagination,
 | originating-facility-scoped. Read-only.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/problem.php';

/**
 * @return list<ProblemSummary>
 */
function dispatchGetPatientProblems(string $tenantId, ?string $facilityId, string $patientId, ?string $status = null): array
{
    /** @var list<ProblemSummary> */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch(new GetPatientProblems($patientId, $status)));
}

test('returns one patient\'s problems, deterministically ordered (occurred_at desc, id asc), as a plain list — no paginator', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    $patientId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $facility, $patientId) {
        Problem::factory()->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
            ->create(['description' => 'older', 'occurred_at' => CarbonImmutable::now()->subDays(10)]);
        Problem::factory()->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
            ->create(['description' => 'newer', 'occurred_at' => CarbonImmutable::now()->subDay()]);
        Problem::factory()->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient((string) Str::uuid7())
            ->create(['description' => 'other patient']);
    });

    $list = dispatchGetPatientProblems((string) $tenant->id, (string) $facility->id, $patientId);

    expect($list)->toBeArray()->toHaveCount(2)
        ->and($list[0])->toBeInstanceOf(ProblemSummary::class)
        ->and($list[0]->description)->toBe('newer')
        ->and($list[1]->description)->toBe('older');
});

test('the optional status filter narrows to ACTIVE or RESOLVED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    $patientId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $facility, $patientId) {
        Problem::factory()->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)->create();
        Problem::factory()->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)->resolved()->create();
    });

    expect(dispatchGetPatientProblems((string) $tenant->id, (string) $facility->id, $patientId))->toHaveCount(2);
    expect(dispatchGetPatientProblems((string) $tenant->id, (string) $facility->id, $patientId, ProblemStatus::ACTIVE))->toHaveCount(1);
    expect(dispatchGetPatientProblems((string) $tenant->id, (string) $facility->id, $patientId, 'RESOLVED'))->toHaveCount(1);
});

test('originating-facility scope: problems originated at a facility the caller is not assigned to are excluded', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = problemFixture();
    $patientId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $facilityA, $patientId) {
        Problem::factory()->forTenant((string) $tenant->id)->atFacility((string) $facilityA->id)->forPatient($patientId)->create(['description' => 'at facility A']);
        Problem::factory()->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->forPatient($patientId)->create(['description' => 'at facility B (out of scope)']);
    });

    $list = dispatchGetPatientProblems((string) $tenant->id, (string) $facilityA->id, $patientId);
    expect($list)->toHaveCount(1)->and($list[0]->description)->toBe('at facility A');
});

test('a patient with no problems -> empty list', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    expect(dispatchGetPatientProblems((string) $tenant->id, (string) $facility->id, (string) Str::uuid7()))->toBe([]);
});

test('tenant isolation: a caller in tenant B sees none of tenant A\'s problems', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = problemFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = problemFixture();
    $patientId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenantA->id, null, fn () => Problem::factory()
        ->forTenant((string) $tenantA->id)->atFacility((string) $facilityA->id)->forPatient($patientId)->create());

    test()->actingAs($userB);
    expect(dispatchGetPatientProblems((string) $tenantB->id, (string) $facilityB->id, $patientId))->toBe([]);
});

test('a caller without clinical.problems.read is denied', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture(['clinical.access', 'clinical.problems.record']);
    expect(fn () => dispatchGetPatientProblems((string) $tenant->id, (string) $facility->id, (string) Str::uuid7()))
        ->toThrow(AuthorizationException::class);
});

test('the read is side-effect free — no audit, no outbox', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    $patientId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, null, fn () => Problem::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)->create());

    dispatchGetPatientProblems((string) $tenant->id, (string) $facility->id, $patientId);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count()))->toBe(0);
});
