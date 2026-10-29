<?php

declare(strict_types=1);

use App\Domains\Patient\Domain\Model\PatientAddress;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at BOTH layers for pat_patient_addresses
 | (ADR-126), mirroring the other pat_ child RLS tests.
 */

uses(RefreshDatabase::class);

test('application layer: an address created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $addressId = TenantContextScope::run($tenantA, null, fn () => (string) PatientAddress::factory()
        ->forTenant($tenantA)->create()->id);

    $foundUnderA = TenantContextScope::run($tenantA, null, fn () => PatientAddress::query()->find($addressId));
    $foundUnderB = TenantContextScope::run($tenantB, null, fn () => PatientAddress::query()->find($addressId));

    expect($foundUnderA)->not->toBeNull()
        ->and($foundUnderB)->toBeNull();
});

it('FORCEs RLS on pat_patient_addresses (database layer, ADR-126)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'patient_id' => (string) Str::uuid7(),
        'type' => 'RESIDENTIAL',
        'region' => 'Dar es Salaam',
        'status' => 'ACTIVE',
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('pat_patient_addresses')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('pat_patient_addresses', $tenantA, $rowId, $tenantB, $row);
});
