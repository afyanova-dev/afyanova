<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectVitalSignsObservation;
use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\ValueObject\VitalSigns;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | IC §17.2 — tenant isolation at BOTH layers for clin_vital_sign_observations
 | (12-clinical-domain.md Invariant 1; ADR-132, ADR-138). Mirrors
 | tests/Feature/Domains/Clinical/ClinicalNoteRlsTest.php. The table is NOT
 | append-only — the one-time supersession stamp is a guarded UPDATE (ADR-138 D7).
 */

uses(RefreshDatabase::class);

test('application layer: an observation created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $obsId = TenantContextScope::run($tenantA, null, fn () => (string) VitalSignsObservation::factory()->forTenant($tenantA)->create()->id);

    expect(TenantContextScope::run($tenantA, null, fn () => VitalSignsObservation::query()->find($obsId)))->not->toBeNull();
    expect(TenantContextScope::run($tenantB, null, fn () => VitalSignsObservation::query()->find($obsId)))->toBeNull();
});

it('FORCEs RLS on clin_vital_sign_observations (database layer)', function () {
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
        'temperature' => 37.0,
        'heart_rate' => 72,
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('clin_vital_sign_observations')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('clin_vital_sign_observations', $tenantA, $rowId, $tenantB, $row);

    $forced = $raw->table('pg_class')->where('relname', 'clin_vital_sign_observations')->value('relforcerowsecurity');
    expect((bool) $forced)->toBeTrue();
});

test('the table is NOT append-only — no RESTRICTIVE no-UPDATE / no-DELETE policy (the one-time supersession stamp is a real UPDATE — ADR-138 D7)', function () {
    $policies = DB::table('pg_policies')
        ->where('tablename', 'clin_vital_sign_observations')
        ->pluck('policyname')
        ->all();

    expect($policies)->not->toContain('clin_vital_sign_observations_no_update')
        ->and($policies)->not->toContain('clin_vital_sign_observations_no_delete')
        ->and($policies)->toContain('tenant_isolation'); // the standard Rls::enable() policy

    // and the correction path really does UPDATE the original (proven end-to-end)
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $v1 = TenantContextScope::run((string) $tenant->id, null, fn () => (string) VitalSignsObservation::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->recordedBy((string) $user->id)->create()->id);
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CorrectVitalSignsObservation(
            newObservationId: (string) Str::uuid7(), supersedesObservationId: $v1, actorId: (string) $user->id,
            measurements: VitalSigns::of(heartRate: 70), reason: 'x',
        ),
    ));
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $v1)->value('superseded_at')))->not->toBeNull();
});

test('a Clinical vitals model never leaks across tenants via the ORM even without an explicit where', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    TenantContextScope::run($tenantA, null, fn () => VitalSignsObservation::factory()->forTenant($tenantA)->create());

    expect(TenantContextScope::run($tenantB, null, fn () => VitalSignsObservation::query()->count()))->toBe(0);
    expect(TenantContextScope::run($tenantA, null, fn () => VitalSignsObservation::query()->count()))->toBe(1);
});
