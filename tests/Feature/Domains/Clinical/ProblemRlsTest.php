<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\UpdateProblem;
use App\Domains\Clinical\Domain\Model\Problem;
use App\Domains\Clinical\Domain\ValueObject\ProblemChangeType;
use App\Domains\Clinical\Domain\ValueObject\ProblemCode;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at BOTH layers for clin_problems and
 | clin_problem_history (12-clinical-domain.md Invariant 1; ADR-132, ADR-140).
 | clin_problems is versioned (NOT append-only); clin_problem_history IS
 | append-only (ADR-140 D-P — the clin_note_amendments precedent).
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/problem.php';

test('application layer: a problem created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $id = TenantContextScope::run($tenantA, null, fn () => (string) Problem::factory()->forTenant($tenantA)->create()->id);

    expect(TenantContextScope::run($tenantA, null, fn () => Problem::query()->find($id)))->not->toBeNull();
    expect(TenantContextScope::run($tenantB, null, fn () => Problem::query()->find($id)))->toBeNull();
});

it('FORCEs RLS on clin_problems (database layer)', function () {
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
        'code' => 'I10',
        'code_system' => 'ICD-10',
        'description' => 'raw insert',
        'status' => 'ACTIVE',
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('clin_problems')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('clin_problems', $tenantA, $rowId, $tenantB, $row);

    $forced = $raw->table('pg_class')->where('relname', 'clin_problems')->value('relforcerowsecurity');
    expect((bool) $forced)->toBeTrue();
});

test('clin_problems is NOT append-only — the C.4 transition commands are guarded versioned UPDATEs', function () {
    $policies = DB::table('pg_policies')->where('tablename', 'clin_problems')->pluck('policyname')->all();
    expect($policies)->not->toContain('clin_problems_no_update')
        ->and($policies)->not->toContain('clin_problems_no_delete')
        ->and($policies)->toContain('tenant_isolation');

    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new UpdateProblem(
        problemId: $problemId, actorId: (string) $user->id, changeType: ProblemChangeType::REVISION,
        coding: ProblemCode::none(), description: 'revised',
    )));

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->value('version')))->toBe(1);
});

it('FORCEs RLS on clin_problem_history and is append-only (UPDATE / DELETE affect zero rows)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    // a parent problem (intra-domain FK target) — inserted on the SAME raw connection
    $problemId = (string) Str::uuid7();
    $problemRow = [
        'id' => $problemId, 'tenant_id' => $tenantA, 'facility_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(), 'encounter_id' => (string) Str::uuid7(),
        'author_user_id' => (string) Str::uuid7(), 'authored_context' => 'CLINICIAN',
        'code' => 'I10', 'code_system' => 'ICD-10', 'description' => 'parent', 'status' => 'ACTIVE',
        'occurred_at' => now(), 'recorded_at' => now(), 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
    ];

    $historyRow = [
        'facility_id' => (string) Str::uuid7(),
        'problem_id' => $problemId,
        'patient_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'change_type' => 'CORRECTION',
        'code' => 'I10',
        'code_system' => 'ICD-10',
        'description' => 'raw pre-change snapshot',
        'status' => 'ACTIVE',
        'reason' => 'raw',
        'author_user_id' => (string) Str::uuid7(),
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $problemRow, $historyRow, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('clin_problems')->insert($problemRow);
        $raw->table('clin_problem_history')->insert([...$historyRow, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    // append-only: even the owning tenant cannot UPDATE / DELETE (0 rows affected, no mutation)
    $raw->transaction(function () use ($raw, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        expect($raw->table('clin_problem_history')->where('id', $rowId)->update(['description' => 'tampered']))->toBe(0);
        expect($raw->table('clin_problem_history')->where('id', $rowId)->delete())->toBe(0);
    });

    expect(Tenancy::run($tenantA, fn () => DB::table('clin_problem_history')->where('id', $rowId)->value('description')))
        ->toBe('raw pre-change snapshot');

    // cross-tenant read isolation
    $raw->transaction(function () use ($raw, $tenantB, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantB]);
        expect($raw->table('clin_problem_history')->where('id', $rowId)->count())->toBe(0);
    });

    $policies = DB::table('pg_policies')->where('tablename', 'clin_problem_history')->pluck('policyname')->all();
    expect($policies)->toContain('clin_problem_history_no_update')
        ->and($policies)->toContain('clin_problem_history_no_delete')
        ->and($policies)->toContain('tenant_isolation');

    $forced = $raw->table('pg_class')->where('relname', 'clin_problem_history')->value('relforcerowsecurity');
    expect((bool) $forced)->toBeTrue();
});

test('a Problem model never leaks across tenants via the ORM even without an explicit where', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    TenantContextScope::run($tenantA, null, fn () => Problem::factory()->forTenant($tenantA)->create());

    expect(TenantContextScope::run($tenantB, null, fn () => Problem::query()->count()))->toBe(0);
    expect(TenantContextScope::run($tenantA, null, fn () => Problem::query()->count()))->toBe(1);
});

test('clin_problems has NO uniqueness constraint beyond the primary key — a patient may hold the same coded problem twice (ADR-140 D-P)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, ['code' => 'I10', 'codeSystem' => 'ICD-10']);
    clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, ['code' => 'I10', 'codeSystem' => 'ICD-10']);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('patient_id', $patientId)->count()))->toBe(2);

    $unique = DB::table('pg_indexes')->where('tablename', 'clin_problems')->pluck('indexdef')
        ->filter(fn ($def) => str_contains((string) $def, 'UNIQUE') && ! str_contains((string) $def, 'clin_problems_pkey'));
    expect($unique)->toBeEmpty();

    $checks = DB::selectOne("select count(*) as c from pg_constraint where conrelid = 'clin_problems'::regclass and contype = 'c'");
    expect((int) $checks->c)->toBe(0);
});
