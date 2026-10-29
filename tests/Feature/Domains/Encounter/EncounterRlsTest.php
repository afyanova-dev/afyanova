<?php

declare(strict_types=1);

use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at BOTH layers for enc_encounters
 | (11-encounter-domain.md §34, Invariant 1; ADR-101, ADR-130). Mirrors
 | tests/Feature/Domains/Patient/PatientRegistrationRlsTest.php.
 */

uses(RefreshDatabase::class);

test('application layer: an encounter created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $encounterId = TenantContextScope::run($tenantA, null, fn () => (string) Encounter::factory()->forTenant($tenantA)->create()->id);

    $foundUnderA = TenantContextScope::run($tenantA, null, fn () => Encounter::query()->find($encounterId));
    $foundUnderB = TenantContextScope::run($tenantB, null, fn () => Encounter::query()->find($encounterId));

    expect($foundUnderA)->not->toBeNull()
        ->and($foundUnderB)->toBeNull();
});

it('FORCEs RLS on enc_encounters (database layer)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(),
        'encounter_number' => 'ENC-'.fake()->unique()->numerify('########'),
        'encounter_type' => 'OUTPATIENT',
        'status' => 'ARRIVED',
        'arrived_at' => now(),
        'created_by_user_id' => (string) Str::uuid7(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('enc_encounters')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('enc_encounters', $tenantA, $rowId, $tenantB, $row);
});

it('FORCEs RLS on enc_encounter_number_counters (database layer)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'next_value' => 5,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('enc_encounter_number_counters')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('enc_encounter_number_counters', $tenantA, $rowId, $tenantB, $row);
});
