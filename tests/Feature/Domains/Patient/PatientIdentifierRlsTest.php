<?php

declare(strict_types=1);

use App\Domains\Patient\Domain\Model\PatientIdentifier;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at BOTH layers for pat_patient_identifiers
 | (ADR-117), mirroring PatientRlsTest / PatientRegistrationRlsTest.
 */

uses(RefreshDatabase::class);

test('application layer: an identifier created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $identifierId = TenantContextScope::run($tenantA, null, fn () => (string) PatientIdentifier::factory()
        ->forTenant($tenantA)->create()->id);

    $foundUnderA = TenantContextScope::run($tenantA, null, fn () => PatientIdentifier::query()->find($identifierId));
    $foundUnderB = TenantContextScope::run($tenantB, null, fn () => PatientIdentifier::query()->find($identifierId));

    expect($foundUnderA)->not->toBeNull()
        ->and($foundUnderB)->toBeNull();
});

it('FORCEs RLS on pat_patient_identifiers (database layer, ADR-117)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'patient_id' => (string) Str::uuid7(),
        'identifier_type' => 'NATIONAL_ID',
        'value' => 'AB-123456',
        'normalized_value' => 'AB123456',
        'verification_status' => 'UNVERIFIED',
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('pat_patient_identifiers')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('pat_patient_identifiers', $tenantA, $rowId, $tenantB, $row);
});
