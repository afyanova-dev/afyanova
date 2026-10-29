<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\EndCareTeamMembership;
use App\Domains\Clinical\Application\Query\GetCareTeamMembership;
use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at BOTH layers for clin_care_team_memberships
 | (12-clinical-domain.md Invariant 1; ADR-132, ADR-142 D-M / D-T). The table is
 | tenant-scoped + FORCE RLS with NO facility predicate (facility isolation is
 | application-layer — ADR-142 D-M); it is NOT append-only (the C.6 commands are
 | guarded versioned UPDATEs of active_to / is_provider_of_record).
 |
 | Facility & tenant Read/Write matrix (ADR-142 D-M — NOT the ADR-141 D-M
 | tenant-wide-read exception):
 |  - same tenant, same facility:      READ allowed, WRITE allowed
 |  - same tenant, different facility: READ denied (NotFound), WRITE denied (NotFound)
 |  - different tenant:                READ denied (RLS), WRITE denied (RLS)
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/care_team.php';

test('application layer: a membership created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $id = TenantContextScope::run($tenantA, null, fn () => (string) CareTeamMembership::factory()->forTenant($tenantA)->create()->id);

    expect(TenantContextScope::run($tenantA, null, fn () => CareTeamMembership::query()->find($id)))->not->toBeNull()
        ->and(TenantContextScope::run($tenantB, null, fn () => CareTeamMembership::query()->find($id)))->toBeNull();
});

it('FORCEs tenant RLS on clin_care_team_memberships with NO facility predicate (database layer)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(),
        'staff_user_id' => (string) Str::uuid7(),
        'team_role' => 'ATTENDING',
        'is_provider_of_record' => false,
        'active_from' => now(),
        'active_to' => null,
        'added_by_user_id' => (string) Str::uuid7(),
        'removed_by_user_id' => null,
        'end_reason' => null,
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('clin_care_team_memberships')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('clin_care_team_memberships', $tenantA, $rowId, $tenantB, $row);

    $forced = $raw->table('pg_class')->where('relname', 'clin_care_team_memberships')->value('relforcerowsecurity');
    expect((bool) $forced)->toBeTrue();

    // RLS predicate is tenant-only — facility_id never appears in a policy expression
    $policyExprs = DB::select("SELECT pg_get_expr(polqual, polrelid) q FROM pg_policy WHERE polrelid = 'clin_care_team_memberships'::regclass");
    foreach ($policyExprs as $e) {
        expect((string) $e->q)->toContain('tenant_id')->and((string) $e->q)->not->toContain('facility_id');
    }
});

test('clin_care_team_memberships is NOT append-only — the C.6 commands are guarded versioned UPDATEs', function () {
    $policies = DB::table('pg_policies')->where('tablename', 'clin_care_team_memberships')->pluck('policyname')->all();

    expect($policies)->not->toContain('clin_care_team_memberships_no_update')
        ->and($policies)->not->toContain('clin_care_team_memberships_no_delete')
        ->and($policies)->toContain('tenant_isolation');

    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new EndCareTeamMembership($membershipId, (string) $user->id)
    ));

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->value('version')))->toBe(1);
});

test('the two partial unique indexes exist with the ratified predicates (ADR-142 D-T)', function () {
    $indexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'clin_care_team_memberships'"))
        ->keyBy('indexname');

    expect($indexes)->toHaveKey('clin_one_provider_of_record_per_encounter')
        ->and($indexes['clin_one_provider_of_record_per_encounter']->indexdef)
        ->toContain('UNIQUE')->toContain('(tenant_id, encounter_id)')->toContain('is_provider_of_record IS TRUE')->toContain('active_to IS NULL')
        ->and($indexes)->toHaveKey('clin_one_active_membership_per_encounter_staff_role')
        ->and($indexes['clin_one_active_membership_per_encounter_staff_role']->indexdef)
        ->toContain('UNIQUE')->toContain('(tenant_id, encounter_id, staff_user_id, team_role)')->toContain('active_to IS NULL');
});

test('same tenant / different facility: reads resolve as NotFound (ADR-142 D-M — NOT the Allergy tenant-wide exception)', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facilityA->id, (string) $user->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facilityA->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);
    Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->update(['facility_id' => (string) Str::uuid7()]));

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facilityA->id, fn () => app(QueryBus::class)->dispatch(new GetCareTeamMembership($membershipId))))
        ->toThrow(NotFound::class);
});
