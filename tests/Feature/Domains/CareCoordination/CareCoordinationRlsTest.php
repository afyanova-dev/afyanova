<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('application layer: CareCoordination entities created under tenant A are invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $facilityA = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();

    $referral = TenantContextScope::run($tenantA, null, fn () => Referral::factory()
        ->forTenant($tenantA)
        ->atFacility($facilityA)
        ->forPatient($patientId)
        ->create());

    $feedback = TenantContextScope::run($tenantA, null, fn () => ReferralFeedback::factory()
        ->forTenant($tenantA)
        ->atFacility($facilityA)
        ->forReferral($referral->id)
        ->create());

    // Visible under tenant A
    expect(TenantContextScope::run($tenantA, null, fn () => Referral::query()->find($referral->id)))->not->toBeNull()
        ->and(TenantContextScope::run($tenantA, null, fn () => ReferralFeedback::query()->find($feedback->id)))->not->toBeNull();

    // Invisible under tenant B
    expect(TenantContextScope::run($tenantB, null, fn () => Referral::query()->find($referral->id)))->toBeNull()
        ->and(TenantContextScope::run($tenantB, null, fn () => ReferralFeedback::query()->find($feedback->id)))->toBeNull();
});

it('FORCEs RLS on care_referrals (database layer)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(),
        'direction' => 'OUTBOUND',
        'implies_handover' => false,
        'source_encounter_id' => null,
        'target_type' => 'INTERNAL',
        'target_ref' => (string) Str::uuid7(),
        'specialty_requested' => 'CARDIOLOGY',
        'priority' => 'ROUTINE',
        'clinical_reason_ref' => null,
        'consent_basis_ref' => null,
        'status' => 'DRAFT',
        'target_encounter_id' => null,
        'created_by_user_id' => (string) Str::uuid7(),
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('care_referrals')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('care_referrals', $tenantA, $rowId, $tenantB, $row);
});

it('FORCEs RLS on care_referral_feedback (database layer)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'referral_id' => (string) Str::uuid7(),
        'sequence' => 1,
        'kind' => null,
        'received_at' => null,
        'received_via' => null,
        'summary' => null,
        'outcome' => null,
        'status' => 'EXPECTED',
        'created_by_user_id' => (string) Str::uuid7(),
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('care_referral_feedback')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('care_referral_feedback', $tenantA, $rowId, $tenantB, $row);
});
