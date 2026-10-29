<?php

declare(strict_types=1);

use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation proved at BOTH layers, independently:
 |   1. application layer (this file's first two tests) — the Eloquent
 |      BelongsToTenant global scope, via TenantContextScope.
 |   2. database layer (assertRlsIsolates) — a raw pgsql_raw connection with
 |      no Eloquent scope, proving FORCE'd RLS alone (ADR-114, ADR-115).
 */

uses(RefreshDatabase::class);

test('application layer: a patient created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $patientId = TenantContextScope::run($tenantA, null, function () use ($tenantA): string {
        $patient = Patient::factory()->forTenant($tenantA)->create();

        return (string) $patient->id;
    });

    $foundUnderA = TenantContextScope::run($tenantA, null, fn () => Patient::query()->find($patientId));
    $foundUnderB = TenantContextScope::run($tenantB, null, fn () => Patient::query()->find($patientId));

    expect($foundUnderA)->not->toBeNull()
        ->and($foundUnderB)->toBeNull();
});

test('application layer: with no tenant context, Patient queries fail closed', function () {
    $tenantA = (string) Str::uuid7();
    $patientId = TenantContextScope::run($tenantA, null, function () use ($tenantA): string {
        $patient = Patient::factory()->forTenant($tenantA)->create();

        return (string) $patient->id;
    });

    // no TenantContextScope::run wrapping this read — global scope has no context
    expect(Patient::query()->find($patientId))->toBeNull();
});

it('FORCEs RLS on pat_patients and pat_patient_number_counters (database layer, ADR-114/115)', function (string $table, array $row) {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $raw->transaction(function () use ($raw, $table, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table($table)->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates($table, $tenantA, $rowId, $tenantB, $row);

    $raw->statement("truncate {$table}");
})->with([
    'pat_patients' => ['pat_patients', [
        'patient_number' => 'PAT-'.substr((string) Str::uuid7(), 0, 8),
        'given_name' => 'Asha',
        'family_name' => 'Mkwawa',
        'display_name' => 'Asha Mkwawa',
        'sex' => 'FEMALE',
        'dob_precision' => 'UNKNOWN',
        'status' => 'ACTIVE',
    ]],
    'pat_patient_number_counters' => ['pat_patient_number_counters', []],
]);
