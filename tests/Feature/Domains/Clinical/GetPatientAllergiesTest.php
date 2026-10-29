<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetPatientAllergies;
use App\Domains\Clinical\Contracts\Dto\AllergySummary;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Clinical\Domain\ValueObject\AllergyStatus;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.5) gate — GetPatientAllergies through the real QueryBus (IC §6.1,
 | §6.2; ADR-141 D-N / D-O / D-M). One patient's list, optional status filters,
 | deterministic order, NO pagination.
 |
 | >>> Q7 (ADR-141 D-M): TENANT-WIDE read. Allergies originated across multiple
 |     facilities within the tenant are returned to the authorized reader. <<<
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/allergy.php';

/**
 * @return list<AllergySummary>
 */
function dispatchGetPatientAllergies(
    string $tenantId,
    ?string $facilityId,
    string $patientId,
    ?string $clinicalStatus = null,
    ?string $verificationStatus = null,
): array {
    /** @var list<AllergySummary> */
    return TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => app(QueryBus::class)->dispatch(new GetPatientAllergies($patientId, $clinicalStatus, $verificationStatus)),
    );
}

test('returns one patient\'s allergies deterministically ordered (category, substance_name, recorded_at, id) without pagination', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    $patientId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $facility, $patientId) {
        Allergy::factory()->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
            ->create(['category' => 'FOOD', 'substance_name' => 'Peanuts']);
        Allergy::factory()->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
            ->create(['category' => 'DRUG', 'substance_name' => 'Penicillin']);
        Allergy::factory()->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
            ->create(['category' => 'DRUG', 'substance_name' => 'Aspirin']);
        Allergy::factory()->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient((string) Str::uuid7())
            ->create(['category' => 'DRUG', 'substance_name' => 'Other Patient Allergy']);
    });

    $list = dispatchGetPatientAllergies((string) $tenant->id, (string) $facility->id, $patientId);

    expect($list)->toBeArray()->toHaveCount(3)
        ->and($list[0])->toBeInstanceOf(AllergySummary::class)
        ->and($list[0]->substanceName)->toBe('Aspirin')    // DRUG, Aspirin
        ->and($list[1]->substanceName)->toBe('Penicillin') // DRUG, Penicillin
        ->and($list[2]->substanceName)->toBe('Peanuts');   // FOOD, Peanuts
});

test('optional status filters narrow the list: clinicalStatus and verificationStatus', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    $patientId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $facility, $patientId) {
        // ACTIVE + SUSPECTED
        Allergy::factory()->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
            ->create(['substance_name' => 'Aspirin', 'clinical_status' => 'ACTIVE', 'verification_status' => 'SUSPECTED']);

        // ACTIVE + CONFIRMED
        Allergy::factory()->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
            ->confirmed()->create(['substance_name' => 'Penicillin', 'clinical_status' => 'ACTIVE']);

        // RESOLVED + CONFIRMED
        Allergy::factory()->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
            ->resolved()->confirmed()->create(['substance_name' => 'Eggs']);

        // INACTIVE + REFUTED
        Allergy::factory()->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
            ->refuted()->create(['substance_name' => 'Milk']);
    });

    // All 4
    expect(dispatchGetPatientAllergies((string) $tenant->id, (string) $facility->id, $patientId))->toHaveCount(4);

    // Filter clinicalStatus = ACTIVE -> 2
    $active = dispatchGetPatientAllergies((string) $tenant->id, (string) $facility->id, $patientId, AllergyStatus::ACTIVE);
    expect($active)->toHaveCount(2);

    // Filter clinicalStatus = RESOLVED -> 1
    $resolved = dispatchGetPatientAllergies((string) $tenant->id, (string) $facility->id, $patientId, AllergyStatus::RESOLVED);
    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]->substanceName)->toBe('Eggs');

    // Filter verificationStatus = CONFIRMED -> 2 (Penicillin active, Eggs resolved)
    $confirmed = dispatchGetPatientAllergies((string) $tenant->id, (string) $facility->id, $patientId, null, AllergyVerificationStatus::CONFIRMED);
    expect($confirmed)->toHaveCount(2);

    // Filter verificationStatus = REFUTED -> 1 (Milk)
    $refuted = dispatchGetPatientAllergies((string) $tenant->id, (string) $facility->id, $patientId, null, AllergyVerificationStatus::REFUTED);
    expect($refuted)->toHaveCount(1)
        ->and($refuted[0]->substanceName)->toBe('Milk')
        ->and($refuted[0]->clinicalStatus)->toBe('INACTIVE');

    // Filter both: ACTIVE + CONFIRMED -> 1 (Penicillin)
    $activeConfirmed = dispatchGetPatientAllergies((string) $tenant->id, (string) $facility->id, $patientId, AllergyStatus::ACTIVE, AllergyVerificationStatus::CONFIRMED);
    expect($activeConfirmed)->toHaveCount(1)
        ->and($activeConfirmed[0]->substanceName)->toBe('Penicillin');
});

// ── Q7: tenant-wide read visibility (ADR-141 D-M / D-N) ─────────────

test('Q7 tenant-wide read: allergies originated at different facilities within the tenant are all returned', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = allergyFixture();
    $facilityB = (string) Str::uuid7();
    $facilityC = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $facilityA, $facilityB, $facilityC, $patientId) {
        Allergy::factory()->forTenant((string) $tenant->id)->atFacility((string) $facilityA->id)->forPatient($patientId)
            ->create(['category' => 'DRUG', 'substance_name' => 'Allergy at Fac A']);
        Allergy::factory()->forTenant((string) $tenant->id)->atFacility($facilityB)->forPatient($patientId)
            ->create(['category' => 'FOOD', 'substance_name' => 'Allergy at Fac B']);
        Allergy::factory()->forTenant((string) $tenant->id)->atFacility($facilityC)->forPatient($patientId)
            ->create(['category' => 'ENVIRONMENT', 'substance_name' => 'Allergy at Fac C']);
    });

    // Caller is active at facilityA, but receives allergies from facilityA, facilityB, facilityC (tenant-wide)
    $list = dispatchGetPatientAllergies((string) $tenant->id, (string) $facilityA->id, $patientId);

    expect($list)->toHaveCount(3);
    $facilityIds = array_map(fn ($s) => $s->facilityId, $list);
    expect($facilityIds)->toContain((string) $facilityA->id)
        ->and($facilityIds)->toContain($facilityB)
        ->and($facilityIds)->toContain($facilityC);
});

test('a patient with no allergies -> empty list', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    expect(dispatchGetPatientAllergies((string) $tenant->id, (string) $facility->id, (string) Str::uuid7()))->toBe([]);
});

test('tenant isolation: a caller in tenant B sees none of tenant A\'s allergies', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = allergyFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = allergyFixture();
    $patientId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenantA->id, null, fn () => Allergy::factory()
        ->forTenant((string) $tenantA->id)->atFacility((string) $facilityA->id)->forPatient($patientId)->create());

    test()->actingAs($userB);
    expect(dispatchGetPatientAllergies((string) $tenantB->id, (string) $facilityB->id, $patientId))->toBe([]);
});

test('a caller without clinical.allergies.read is denied', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture(['clinical.access', 'clinical.allergies.record']);
    expect(fn () => dispatchGetPatientAllergies((string) $tenant->id, (string) $facility->id, (string) Str::uuid7()))
        ->toThrow(AuthorizationException::class);
});

test('the read is side-effect free — no audit, no outbox', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    $patientId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, null, fn () => Allergy::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)->create());

    dispatchGetPatientAllergies((string) $tenant->id, (string) $facility->id, $patientId);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count()))->toBe(0);
});
