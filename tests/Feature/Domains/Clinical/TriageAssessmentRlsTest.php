<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectTriageAssessment;
use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at BOTH layers for clin_triage_assessments
 | (12-clinical-domain.md Invariant 1; ADR-132, ADR-145). The table is NOT
 | append-only — the one-time supersession stamp is a guarded UPDATE (ADR-145
 | D-F).
 */

uses(RefreshDatabase::class);

test('application layer: a triage assessment created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $id = TenantContextScope::run($tenantA, null, fn () => (string) TriageAssessment::factory()->forTenant($tenantA)->create()->id);

    expect(TenantContextScope::run($tenantA, null, fn () => TriageAssessment::query()->find($id)))->not->toBeNull();
    expect(TenantContextScope::run($tenantB, null, fn () => TriageAssessment::query()->find($id)))->toBeNull();
});

it('FORCEs RLS on clin_triage_assessments (database layer)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'author_user_id' => (string) Str::uuid7(),
        'authored_context' => 'NURSING',
        'chief_complaint' => 'raw insert',
        'triage_priority' => 'NORMAL',
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('clin_triage_assessments')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('clin_triage_assessments', $tenantA, $rowId, $tenantB, $row);

    $forced = $raw->table('pg_class')->where('relname', 'clin_triage_assessments')->value('relforcerowsecurity');
    expect((bool) $forced)->toBeTrue();
});

test('the table is NOT append-only — no RESTRICTIVE no-UPDATE / no-DELETE policy (the one-time supersession stamp is a real UPDATE — ADR-145 D-F)', function () {
    $policies = DB::table('pg_policies')->where('tablename', 'clin_triage_assessments')->pluck('policyname')->all();

    expect($policies)->not->toContain('clin_triage_assessments_no_update')
        ->and($policies)->not->toContain('clin_triage_assessments_no_delete')
        ->and($policies)->toContain('tenant_isolation');

    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $v1 = clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CorrectTriageAssessment(
            newTriageAssessmentId: (string) Str::uuid7(), supersedesTriageAssessmentId: $v1, actorId: (string) $user->id,
            chiefComplaint: 'x', triagePriority: 'HIGH', reason: 'y',
        ),
    ));

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->where('id', $v1)->value('superseded_at')))->not->toBeNull();
});

test('no facility predicate on the RLS policy — isolation is tenant-only at the DB, facility scope is application-layer (ADR-145 D-P)', function () {
    $usingExpr = DB::table('pg_policies')
        ->where('tablename', 'clin_triage_assessments')
        ->where('policyname', 'tenant_isolation')
        ->value('qual');

    expect((string) $usingExpr)->not->toContain('facility_id');
});
