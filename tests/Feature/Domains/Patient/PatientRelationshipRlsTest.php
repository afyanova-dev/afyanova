<?php

declare(strict_types=1);

use App\Domains\Patient\Domain\Model\PatientRelationship;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at BOTH layers for pat_patient_relationships
 | (ADR-126), mirroring PatientIdentifierRlsTest / PatientRegistrationRlsTest.
 */

uses(RefreshDatabase::class);

test('application layer: a relationship created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $relationshipId = TenantContextScope::run($tenantA, null, fn () => (string) PatientRelationship::factory()
        ->forTenant($tenantA)->create()->id);

    $foundUnderA = TenantContextScope::run($tenantA, null, fn () => PatientRelationship::query()->find($relationshipId));
    $foundUnderB = TenantContextScope::run($tenantB, null, fn () => PatientRelationship::query()->find($relationshipId));

    expect($foundUnderA)->not->toBeNull()
        ->and($foundUnderB)->toBeNull();
});

it('FORCEs RLS on pat_patient_relationships (database layer, ADR-126)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'patient_id' => (string) Str::uuid7(),
        'role' => 'EMERGENCY_CONTACT',
        'related_name' => 'Neema Mkwawa',
        'status' => 'ACTIVE',
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('pat_patient_relationships')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('pat_patient_relationships', $tenantA, $rowId, $tenantB, $row);
});
