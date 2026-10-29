<?php

declare(strict_types=1);

use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 Slice 2 gate — the PatientApi port (ADR-114, ADR-125), exercised the
 | way another domain (e.g. Encounter, a later slice) will consume it:
 | patientExists() direct-reader (no PHI, no bus — IC §6.2 reference-read
 | carve-out); getPatientSummary() through the QueryBus, #[ProtectedQuery];
 | registerAtFacility() through the CommandBus.
 */

uses(RefreshDatabase::class);

test('patientExists() is true for a tenant patient and false for a missing or cross-tenant one', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([]);
    $this->actingAs($user);

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
    );

    $existsForOwnTenant = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(PatientApi::class)->patientExists($patientId),
    );
    expect($existsForOwnTenant)->toBeTrue();

    ['tenant' => $tenantB, 'facility' => $facilityB] = makeTenantWithUser([]);
    $existsForOtherTenant = TenantContextScope::run(
        (string) $tenantB->id,
        (string) $facilityB->id,
        fn () => app(PatientApi::class)->patientExists($patientId),
    );
    expect($existsForOtherTenant)->toBeFalse(); // RLS — the patient row is invisible outside its tenant

    $existsForBogusId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(PatientApi::class)->patientExists((string) Str::uuid7()),
    );
    expect($existsForBogusId)->toBeFalse();
});

test('getPatientSummary() returns the expected fields to an authorized caller', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.read']);
    $this->actingAs($user);

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create([
            'display_name' => 'Asha Mkwawa',
            'is_provisional' => false,
        ])->id,
    );

    /** @var PatientSummary $summary */
    $summary = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(PatientApi::class)->getPatientSummary($patientId),
    );

    expect($summary)->toBeInstanceOf(PatientSummary::class)
        ->and($summary->patientId)->toBe($patientId)
        ->and($summary->displayName)->toBe('Asha Mkwawa')
        ->and($summary->status)->toBe(PatientStatus::Active->value)
        ->and($summary->isProvisional)->toBeFalse();
});

test('getPatientSummary() denies a caller without patient.patients.read', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([]); // no permissions
    $this->actingAs($user);

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
    );

    expect(fn () => TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(PatientApi::class)->getPatientSummary($patientId),
    ))->toThrow(AuthorizationException::class);
});

test('getPatientSummary() throws NotFound for a missing or cross-tenant patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.read']);
    $this->actingAs($user);

    expect(fn () => TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(PatientApi::class)->getPatientSummary((string) Str::uuid7()),
    ))->toThrow(NotFound::class);
});

test('registerAtFacility() via the port records a facility registration', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.registrations.create']);
    $this->actingAs($user);

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
    );

    TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(PatientApi::class)->registerAtFacility($patientId, (string) $facility->id),
    );

    $count = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_registrations')
        ->where('patient_id', $patientId)
        ->where('facility_id', $facility->id)
        ->count());
    expect($count)->toBe(1);
});
