<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectDiagnosis;
use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Domains\Clinical\Domain\ValueObject\DiagnosisCode;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at BOTH layers for clin_diagnoses
 | (12-clinical-domain.md Invariant 1; ADR-132, ADR-139). The table is NOT
 | append-only — the one-time supersession stamp is a guarded UPDATE (ADR-139 D4).
 */

uses(RefreshDatabase::class);

test('application layer: a diagnosis created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $id = TenantContextScope::run($tenantA, null, fn () => (string) Diagnosis::factory()->forTenant($tenantA)->create()->id);

    expect(TenantContextScope::run($tenantA, null, fn () => Diagnosis::query()->find($id)))->not->toBeNull();
    expect(TenantContextScope::run($tenantB, null, fn () => Diagnosis::query()->find($id)))->toBeNull();
});

it('FORCEs RLS on clin_diagnoses (database layer)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'author_user_id' => (string) Str::uuid7(),
        'authored_context' => 'CLINICIAN',
        'code' => 'B54',
        'code_system' => 'ICD-10',
        'description' => 'raw insert',
        'clinical_status' => 'WORKING',
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('clin_diagnoses')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('clin_diagnoses', $tenantA, $rowId, $tenantB, $row);

    $forced = $raw->table('pg_class')->where('relname', 'clin_diagnoses')->value('relforcerowsecurity');
    expect((bool) $forced)->toBeTrue();
});

test('the table is NOT append-only — no RESTRICTIVE no-UPDATE / no-DELETE policy (the one-time supersession stamp is a real UPDATE — ADR-139 D4)', function () {
    $policies = DB::table('pg_policies')->where('tablename', 'clin_diagnoses')->pluck('policyname')->all();

    expect($policies)->not->toContain('clin_diagnoses_no_update')
        ->and($policies)->not->toContain('clin_diagnoses_no_delete')
        ->and($policies)->toContain('tenant_isolation');

    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $v1 = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Diagnosis::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->create()->id);
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CorrectDiagnosis(
            newDiagnosisId: (string) Str::uuid7(), supersedesDiagnosisId: $v1, actorId: (string) $user->id,
            coding: DiagnosisCode::none(), description: 'x', reason: 'y',
        ),
    ));
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $v1)->value('superseded_at')))->not->toBeNull();
});

test('a Clinical diagnosis model never leaks across tenants via the ORM even without an explicit where', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    TenantContextScope::run($tenantA, null, fn () => Diagnosis::factory()->forTenant($tenantA)->create());

    expect(TenantContextScope::run($tenantB, null, fn () => Diagnosis::query()->count()))->toBe(0);
    expect(TenantContextScope::run($tenantA, null, fn () => Diagnosis::query()->count()))->toBe(1);
});
