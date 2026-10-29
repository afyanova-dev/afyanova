<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\UpdateAllergy;
use App\Domains\Clinical\Application\Query\GetAllergy;
use App\Domains\Clinical\Contracts\Dto\AllergySummary;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Clinical\Domain\ValueObject\AllergyChangeType;
use App\Domains\Clinical\Domain\ValueObject\AllergySubstance;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at BOTH layers for clin_allergies and
 | clin_allergy_history (12-clinical-domain.md Invariant 1; ADR-132, ADR-141).
 | clin_allergies is versioned (NOT append-only); clin_allergy_history IS
 | append-only (ADR-141 D-P).
 |
 | Q7 Facility & Tenant Read/Write matrix:
 | - Same tenant, same facility:      READ = allowed, WRITE = allowed
 | - Same tenant, different facility: READ = allowed (Q7 safety exception), WRITE = denied
 | - Different tenant:                READ = denied (RLS), WRITE = denied (RLS)
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/allergy.php';

test('application layer: an allergy created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $id = TenantContextScope::run($tenantA, null, fn () => (string) Allergy::factory()->forTenant($tenantA)->create()->id);

    expect(TenantContextScope::run($tenantA, null, fn () => Allergy::query()->find($id)))->not->toBeNull();
    expect(TenantContextScope::run($tenantB, null, fn () => Allergy::query()->find($id)))->toBeNull();
});

it('FORCEs RLS on clin_allergies (database layer)', function () {
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
        'category' => 'DRUG',
        'substance_name' => 'Penicillin',
        'substance_code' => '764146007',
        'substance_code_system' => 'SNOMED-CT',
        'reaction_description' => 'Hives',
        'severity' => 'MODERATE',
        'clinical_status' => 'ACTIVE',
        'verification_status' => 'SUSPECTED',
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('clin_allergies')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('clin_allergies', $tenantA, $rowId, $tenantB, $row);

    $forced = $raw->table('pg_class')->where('relname', 'clin_allergies')->value('relforcerowsecurity');
    expect((bool) $forced)->toBeTrue();
});

test('clin_allergies is NOT append-only — the C.5 transition commands are guarded versioned UPDATEs', function () {
    $policies = DB::table('pg_policies')->where('tablename', 'clin_allergies')->pluck('policyname')->all();
    expect($policies)->not->toContain('clin_allergies_no_update')
        ->and($policies)->not->toContain('clin_allergies_no_delete')
        ->and($policies)->toContain('tenant_isolation');

    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new UpdateAllergy(
        allergyId: $allergyId,
        actorId: (string) $user->id,
        changeType: AllergyChangeType::REVISION,
        category: 'DRUG',
        substance: AllergySubstance::of('Penicillin G'),
    )));

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->value('version')))->toBe(1);
});

it('FORCEs RLS on clin_allergy_history and is append-only (UPDATE / DELETE affect zero rows)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $allergyId = (string) Str::uuid7();
    $allergyRow = [
        'id' => $allergyId,
        'tenant_id' => $tenantA,
        'facility_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'author_user_id' => (string) Str::uuid7(),
        'authored_context' => 'CLINICIAN',
        'category' => 'DRUG',
        'substance_name' => 'Penicillin',
        'substance_code' => '764146007',
        'substance_code_system' => 'SNOMED-CT',
        'reaction_description' => 'Hives',
        'severity' => 'MODERATE',
        'clinical_status' => 'ACTIVE',
        'verification_status' => 'SUSPECTED',
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $historyRow = [
        'facility_id' => (string) Str::uuid7(),
        'allergy_id' => $allergyId,
        'patient_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'change_kind' => 'CONTENT',
        'change_type' => 'CORRECTION',
        'category' => 'DRUG',
        'substance_name' => 'Penicillin',
        'substance_code' => '764146007',
        'substance_code_system' => 'SNOMED-CT',
        'reaction_description' => 'Hives',
        'severity' => 'MODERATE',
        'clinical_status' => 'ACTIVE',
        'verification_status' => 'SUSPECTED',
        'reason' => 'raw correction',
        'author_user_id' => (string) Str::uuid7(),
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $allergyRow, $historyRow, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('clin_allergies')->insert($allergyRow);
        $raw->table('clin_allergy_history')->insert([...$historyRow, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    // append-only: even the owning tenant cannot UPDATE / DELETE (0 rows affected)
    $raw->transaction(function () use ($raw, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        expect($raw->table('clin_allergy_history')->where('id', $rowId)->update(['substance_name' => 'tampered']))->toBe(0);
        expect($raw->table('clin_allergy_history')->where('id', $rowId)->delete())->toBe(0);
    });

    expect(Tenancy::run($tenantA, fn () => DB::table('clin_allergy_history')->where('id', $rowId)->value('substance_name')))
        ->toBe('Penicillin');

    // cross-tenant read isolation
    $raw->transaction(function () use ($raw, $tenantB, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantB]);
        expect($raw->table('clin_allergy_history')->where('id', $rowId)->count())->toBe(0);
    });

    $policies = DB::table('pg_policies')->where('tablename', 'clin_allergy_history')->pluck('policyname')->all();
    expect($policies)->toContain('clin_allergy_history_no_update')
        ->and($policies)->toContain('clin_allergy_history_no_delete')
        ->and($policies)->toContain('tenant_isolation');

    $forced = $raw->table('pg_class')->where('relname', 'clin_allergy_history')->value('relforcerowsecurity');
    expect((bool) $forced)->toBeTrue();
});

test('an Allergy model never leaks across tenants via the ORM even without an explicit where', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    TenantContextScope::run($tenantA, null, fn () => Allergy::factory()->forTenant($tenantA)->create());

    expect(TenantContextScope::run($tenantB, null, fn () => Allergy::query()->count()))->toBe(0);
    expect(TenantContextScope::run($tenantA, null, fn () => Allergy::query()->count()))->toBe(1);
});

// ── Q7: Facility and Tenant Read/Write Matrix ────────────────────────

test('Q7 matrix: same tenant / same facility -> READ allowed, WRITE allowed', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    // WRITE allowed
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    // READ allowed
    /** @var AllergySummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetAllergy($allergyId)));
    expect($summary->allergyId)->toBe($allergyId);
});

test('Q7 matrix: same tenant / different facility -> READ allowed (Q7 exception), WRITE denied', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = allergyFixture();
    $facilityB = (string) Str::uuid7(); // different facility in same tenant

    // Allergy originated at facilityB
    $allergyId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Allergy::factory()
        ->forTenant((string) $tenant->id)->atFacility($facilityB)->create()->id);

    // Caller is active at facilityA (same tenant, different facility)
    // 1. READ is allowed! (Q7 patient safety exception)
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facilityA->id, fn () => app(QueryBus::class)->dispatch(new GetAllergy($allergyId)));
    expect($summary->allergyId)->toBe($allergyId);

    // 2. WRITE is denied! (originating-facility refinement in policy)
    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facilityA->id, fn () => app(CommandBus::class)->dispatch(new UpdateAllergy(
        allergyId: $allergyId,
        actorId: (string) $user->id,
        changeType: AllergyChangeType::REVISION,
        category: 'DRUG',
        substance: AllergySubstance::of('New Penicillin'),
    ))))->toThrow(AuthorizationException::class);
});

test('Q7 matrix: different tenant -> READ denied (NotFound / RLS), WRITE denied (NotFound / RLS)', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = allergyFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = allergyFixture();

    $allergyId = TenantContextScope::run((string) $tenantA->id, null, fn () => (string) Allergy::factory()
        ->forTenant((string) $tenantA->id)->atFacility((string) $facilityA->id)->create()->id);

    test()->actingAs($userB);

    // READ denied
    expect(fn () => TenantContextScope::run((string) $tenantB->id, (string) $facilityB->id, fn () => app(QueryBus::class)->dispatch(new GetAllergy($allergyId))))
        ->toThrow(NotFound::class);

    // WRITE denied
    expect(fn () => TenantContextScope::run((string) $tenantB->id, (string) $facilityB->id, fn () => app(CommandBus::class)->dispatch(new UpdateAllergy(
        allergyId: $allergyId,
        actorId: (string) $userB->id,
        changeType: AllergyChangeType::REVISION,
        category: 'DRUG',
        substance: AllergySubstance::of('Cross-Tenant Penicillin'),
    ))))->toThrow(NotFound::class);
});

test('clin_allergies has NO uniqueness constraint beyond the primary key (ADR-141 D-P)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'substanceName' => 'Penicillin',
        'substanceCode' => '764146007',
        'substanceCodeSystem' => 'SNOMED-CT',
    ]);
    clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'substanceName' => 'Penicillin',
        'substanceCode' => '764146007',
        'substanceCodeSystem' => 'SNOMED-CT',
    ]);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('patient_id', $patientId)->count()))->toBe(2);

    $unique = DB::table('pg_indexes')->where('tablename', 'clin_allergies')->pluck('indexdef')
        ->filter(fn ($def) => str_contains((string) $def, 'UNIQUE') && ! str_contains((string) $def, 'clin_allergies_pkey'));
    expect($unique)->toBeEmpty();
});
