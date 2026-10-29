<?php

declare(strict_types=1);

use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientRegistration;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at both layers for pat_patient_registrations
 | (ADR-114), mirroring tests/Feature/Domains/Patient/PatientRlsTest.php for
 | the Slice 1 pat_patients table.
 */

uses(RefreshDatabase::class);

test('application layer: a registration created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $registrationId = TenantContextScope::run($tenantA, null, function () use ($tenantA): string {
        $registration = PatientRegistration::factory()->forTenant($tenantA)->create();

        return (string) $registration->id;
    });

    $foundUnderA = TenantContextScope::run($tenantA, null, fn () => PatientRegistration::query()->find($registrationId));
    $foundUnderB = TenantContextScope::run($tenantB, null, fn () => PatientRegistration::query()->find($registrationId));

    expect($foundUnderA)->not->toBeNull()
        ->and($foundUnderB)->toBeNull();
});

it('FORCEs RLS on pat_patient_registrations (database layer, ADR-114)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    // the FK to pat_patients needs a real row in the same tenant to reference
    $patientId = TenantContextScope::run($tenantA, null, fn () => (string) Patient::factory()
        ->forTenant($tenantA)->create()->id);

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'patient_id' => $patientId,
        'status' => 'ACTIVE',
        'first_registered_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('pat_patient_registrations')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('pat_patient_registrations', $tenantA, $rowId, $tenantB, $row);
});
