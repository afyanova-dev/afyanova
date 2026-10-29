<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Domains\Clinical\Domain\Model\ProcedureDefinition;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at BOTH layers for the CP.1 tables (ADR-132,
 | ADR-146). clin_procedures / clin_procedure_definitions are mutable;
 | clin_procedure_amendments / clin_procedure_materials are append-only.
 */

uses(RefreshDatabase::class);

test('application layer: a ProcedureDefinition created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $id = TenantContextScope::run($tenantA, null, fn () => (string) ProcedureDefinition::factory()->forTenant($tenantA)->create()->id);

    expect(TenantContextScope::run($tenantA, null, fn () => ProcedureDefinition::query()->find($id)))->not->toBeNull();
    expect(TenantContextScope::run($tenantB, null, fn () => ProcedureDefinition::query()->find($id)))->toBeNull();
});

test('application layer: a ClinicalProcedure created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $id = TenantContextScope::run($tenantA, null, fn () => (string) ClinicalProcedure::factory()->forTenant($tenantA)->create()->id);

    expect(TenantContextScope::run($tenantA, null, fn () => ClinicalProcedure::query()->find($id)))->not->toBeNull();
    expect(TenantContextScope::run($tenantB, null, fn () => ClinicalProcedure::query()->find($id)))->toBeNull();
});

it('FORCEs RLS on all four CP.1 application tables (database layer)', function (string $table) {
    $forced = DB::connection('pgsql_raw')->table('pg_class')->where('relname', $table)->value('relforcerowsecurity');
    expect((bool) $forced)->toBeTrue();
})->with([
    'clin_procedure_definitions',
    'clin_procedures',
    'clin_procedure_amendments',
    'clin_procedure_materials',
]);

test('clin_procedure_amendments and clin_procedure_materials are append-only; clin_procedures and clin_procedure_definitions are NOT', function () {
    foreach (['clin_procedure_amendments', 'clin_procedure_materials'] as $table) {
        $policies = DB::table('pg_policies')->where('tablename', $table)->pluck('policyname')->all();
        expect($policies)->toContain("{$table}_no_update")->and($policies)->toContain("{$table}_no_delete");
    }

    foreach (['clin_procedures', 'clin_procedure_definitions'] as $table) {
        $policies = DB::table('pg_policies')->where('tablename', $table)->pluck('policyname')->all();
        expect($policies)->not->toContain("{$table}_no_update")->and($policies)->not->toContain("{$table}_no_delete");
    }
});

test('no facility predicate on clin_procedures\' RLS policy — isolation is tenant-only, facility scope is application-layer (ADR-146 D-U)', function () {
    $usingExpr = DB::table('pg_policies')
        ->where('tablename', 'clin_procedures')->where('policyname', 'tenant_isolation')->value('qual');

    expect((string) $usingExpr)->not->toContain('facility_id');
});

test('database layer: a raw cross-tenant read of clin_procedures returns nothing (RLS, not the app)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();
    $definitionId = (string) Str::uuid7();

    $raw->transaction(function () use ($raw, $tenantA, $definitionId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('clin_procedure_definitions')->insert([
            'id' => $definitionId, 'tenant_id' => $tenantA, 'code' => 'RAW-1', 'name' => 'Raw', 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'author_user_id' => (string) Str::uuid7(),
        'authored_context' => 'CLINICIAN',
        'procedure_number' => 'PROC-00000099',
        'status' => 'REQUESTED',
        'definition_id' => $definitionId,
        'definition_version' => 1,
        'requested_by_user_id' => (string) Str::uuid7(),
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('clin_procedures')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('clin_procedures', $tenantA, $rowId, $tenantB, $row);
});
