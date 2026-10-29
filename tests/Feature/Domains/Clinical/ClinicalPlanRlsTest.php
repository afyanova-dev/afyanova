<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('application layer: a ClinicalPlan created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $id = TenantContextScope::run($tenantA, null, fn () => (string) ClinicalPlan::factory()->forTenant($tenantA)->create()->id);

    expect(TenantContextScope::run($tenantA, null, fn () => ClinicalPlan::query()->find($id)))->not->toBeNull();
    expect(TenantContextScope::run($tenantB, null, fn () => ClinicalPlan::query()->find($id)))->toBeNull();
});

test('append-only: clin_plan_history forbids direct update and delete at database layer', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $planId = (string) Str::uuid7();
    $planRow = [
        'id' => $planId,
        'tenant_id' => $tenantA,
        'facility_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'author_user_id' => (string) Str::uuid7(),
        'authored_context' => 'CLINICIAN',
        'plan_type' => 'GENERAL',
        'clinical_intent' => 'Parent plan',
        'status' => 'ACTIVE',
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $historyRow = [
        'facility_id' => (string) Str::uuid7(),
        'plan_id' => $planId,
        'patient_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'change_type' => 'CORRECTION',
        'plan_type' => 'GENERAL',
        'status' => 'ACTIVE',
        'clinical_intent' => 'raw pre-change snapshot',
        'reason' => 'raw',
        'author_user_id' => (string) Str::uuid7(),
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $planRow, $historyRow, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('clin_plans')->insert($planRow);
        $raw->table('clin_plan_history')->insert([...$historyRow, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    // append-only: even the owning tenant cannot UPDATE / DELETE (0 rows affected, no mutation)
    $raw->transaction(function () use ($raw, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        expect($raw->table('clin_plan_history')->where('id', $rowId)->update(['clinical_intent' => 'tampered']))->toBe(0);
        expect($raw->table('clin_plan_history')->where('id', $rowId)->delete())->toBe(0);
    });

    expect(Tenancy::run($tenantA, fn () => DB::table('clin_plan_history')->where('id', $rowId)->value('clinical_intent')))
        ->toBe('raw pre-change snapshot');

    // cross-tenant read isolation
    $raw->transaction(function () use ($raw, $tenantB, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantB]);
        expect($raw->table('clin_plan_history')->where('id', $rowId)->count())->toBe(0);
    });

    $policies = DB::table('pg_policies')->where('tablename', 'clin_plan_history')->pluck('policyname')->all();
    expect($policies)->toContain('clin_plan_history_no_update')
        ->and($policies)->toContain('clin_plan_history_no_delete')
        ->and($policies)->toContain('tenant_isolation');

    $forced = $raw->table('pg_class')->where('relname', 'clin_plan_history')->value('relforcerowsecurity');
    expect((bool) $forced)->toBeTrue();
});
