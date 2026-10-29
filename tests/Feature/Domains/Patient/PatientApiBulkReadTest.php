<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Patient;

use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | Increment B feature gate — PatientApi::getPatientSummaries bulk read
 | (IC §6.1, §6.2, §6.3; 10-patient-domain.md Invariant 12; ADR-125).
 */

uses(RefreshDatabase::class);

test('getPatientSummaries() returns associative map of PatientSummary keyed by patientId', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.read']);
    test()->actingAs($user);

    $patientId1 = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create([
            'display_name' => 'Asha Mkwawa',
            'is_provisional' => false,
        ])->id,
    );

    $patientId2 = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create([
            'display_name' => 'Juma Bakari',
            'is_provisional' => true,
        ])->id,
    );

    /** @var array<string, PatientSummary> $results */
    $results = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(PatientApi::class)->getPatientSummaries([$patientId1, $patientId2]),
    );

    expect($results)->toBeArray()
        ->and($results)->toHaveCount(2)
        ->and(array_keys($results))->toEqualCanonicalizing([$patientId1, $patientId2]);

    expect($results[$patientId1])->toBeInstanceOf(PatientSummary::class)
        ->and($results[$patientId1]->patientId)->toBe($patientId1)
        ->and($results[$patientId1]->displayName)->toBe('Asha Mkwawa')
        ->and($results[$patientId1]->status)->toBe(PatientStatus::Active->value)
        ->and($results[$patientId1]->isProvisional)->toBeFalse();

    expect($results[$patientId2])->toBeInstanceOf(PatientSummary::class)
        ->and($results[$patientId2]->patientId)->toBe($patientId2)
        ->and($results[$patientId2]->displayName)->toBe('Juma Bakari')
        ->and($results[$patientId2]->status)->toBe(PatientStatus::Active->value)
        ->and($results[$patientId2]->isProvisional)->toBeTrue();
});

test('getPatientSummaries() executes a single bulk query (no N+1)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.read']);
    test()->actingAs($user);

    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = TenantContextScope::run(
            (string) $tenant->id,
            (string) $facility->id,
            fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
        );
    }

    DB::enableQueryLog();

    $results = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(PatientApi::class)->getPatientSummaries($ids),
    );

    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    expect($results)->toHaveCount(5);

    // Filter query log for queries against pat_patients
    $patientQueries = array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains((string) $q['query'], 'pat_patients'),
    );

    // Exactly one bulk query on pat_patients — NOT 5 separate queries
    expect($patientQueries)->toHaveCount(1);
});

test('getPatientSummaries() deduplicates requested IDs', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.read']);
    test()->actingAs($user);

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
    );

    /** @var array<string, PatientSummary> $results */
    $results = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(PatientApi::class)->getPatientSummaries([$patientId, $patientId, $patientId]),
    );

    expect($results)->toHaveCount(1)
        ->and($results)->toHaveKey($patientId);
});

test('missing patient IDs are omitted from results (no dummy records, no 404)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.read']);
    test()->actingAs($user);

    $existingId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
    );
    $missingId = (string) Str::uuid7();

    /** @var array<string, PatientSummary> $results */
    $results = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(PatientApi::class)->getPatientSummaries([$existingId, $missingId]),
    );

    expect($results)->toHaveCount(1)
        ->and($results)->toHaveKey($existingId)
        ->and($results)->not->toHaveKey($missingId);
});

test('getPatientSummaries() preserves tenant RLS isolation', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] =
        makeTenantWithUser(['patient.patients.read']);
    ['tenant' => $tenantB, 'facility' => $facilityB] =
        makeTenantWithUser(['patient.patients.read']);

    $patientAId = TenantContextScope::run(
        (string) $tenantA->id,
        (string) $facilityA->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenantA->id)->create()->id,
    );

    $patientBId = TenantContextScope::run(
        (string) $tenantB->id,
        (string) $facilityB->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenantB->id)->create()->id,
    );

    test()->actingAs($userA);

    /** @var array<string, PatientSummary> $results */
    $results = TenantContextScope::run(
        (string) $tenantA->id,
        (string) $facilityA->id,
        fn () => app(PatientApi::class)->getPatientSummaries([$patientAId, $patientBId]),
    );

    // Tenant A caller receives their own patient, but tenant B patient is omitted by RLS
    expect($results)->toHaveCount(1)
        ->and($results)->toHaveKey($patientAId)
        ->and($results)->not->toHaveKey($patientBId);
});

test('getPatientSummaries() denies a caller without patient.patients.read (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser([]); // No permissions
    test()->actingAs($user);

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
    );

    expect(fn () => TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(PatientApi::class)->getPatientSummaries([$patientId]),
    ))->toThrow(AuthorizationException::class);
});

test('getPatientSummaries() with empty array returns empty map without querying database', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.read']);
    test()->actingAs($user);

    DB::enableQueryLog();

    $results = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(PatientApi::class)->getPatientSummaries([]),
    );

    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    expect($results)->toBeArray()->toBeEmpty();

    $patientQueries = array_filter(
        $queryLog,
        static fn (array $q): bool => str_contains((string) $q['query'], 'pat_patients'),
    );
    expect($patientQueries)->toBeEmpty();
});

test('getPatientSummaries() is strictly read-only and mutates no patient state', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.read']);
    test()->actingAs($user);

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
    );

    $beforeRow = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->first());

    TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(PatientApi::class)->getPatientSummaries([$patientId]),
    );

    $afterRow = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->first());

    expect($afterRow->updated_at)->toBe($beforeRow->updated_at)
        ->and($afterRow->version)->toBe($beforeRow->version);
});
