<?php

declare(strict_types=1);

use App\Domains\Patient\Domain\Model\PatientContact;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at BOTH layers for pat_patient_contacts
 | (ADR-126), mirroring PatientIdentifierRlsTest / PatientRelationshipRlsTest.
 */

uses(RefreshDatabase::class);

test('application layer: a contact created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $contactId = TenantContextScope::run($tenantA, null, fn () => (string) PatientContact::factory()
        ->forTenant($tenantA)->create()->id);

    $foundUnderA = TenantContextScope::run($tenantA, null, fn () => PatientContact::query()->find($contactId));
    $foundUnderB = TenantContextScope::run($tenantB, null, fn () => PatientContact::query()->find($contactId));

    expect($foundUnderA)->not->toBeNull()
        ->and($foundUnderB)->toBeNull();
});

it('FORCEs RLS on pat_patient_contacts (database layer, ADR-126)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'patient_id' => (string) Str::uuid7(),
        'type' => 'MOBILE',
        'value' => '+255700111222',
        'normalized_value' => '255700111222',
        'status' => 'ACTIVE',
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('pat_patient_contacts')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('pat_patient_contacts', $tenantA, $rowId, $tenantB, $row);
});
