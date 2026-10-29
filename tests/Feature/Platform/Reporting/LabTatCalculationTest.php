<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Reporting;

use App\Platform\Reporting\Domain\Model\LabTatDaily;
use App\Platform\Reporting\Infrastructure\Projection\LabTatProjectionBuilder;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/reporting.php';

test('LabTatProjectionBuilder computes actual average turnaround time and target counts from order timestamps', function (): void {
    $fx = reportingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $baseTime = CarbonImmutable::parse('2026-09-10 08:00:00');

    // Order 1: 60 minutes TAT (08:00 to 09:00) -> completed within 120 min target
    DB::table('lab_orders')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'created_by_user_id' => (string) $fx['user']->id,
        'order_number' => 'LAB-TAT-01',
        'priority' => 'ROUTINE',
        'status' => 'COMPLETED',
        'occurred_at' => $baseTime,
        'recorded_at' => $baseTime,
        'created_at' => $baseTime,
        'updated_at' => $baseTime->addMinutes(60),
    ]);

    // Order 2: 180 minutes TAT (08:00 to 11:00) -> breached 120 min target
    DB::table('lab_orders')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'created_by_user_id' => (string) $fx['user']->id,
        'order_number' => 'LAB-TAT-02',
        'priority' => 'ROUTINE',
        'status' => 'COMPLETED',
        'occurred_at' => $baseTime,
        'recorded_at' => $baseTime,
        'created_at' => $baseTime,
        'updated_at' => $baseTime->addMinutes(180),
    ]);

    // Order 3: in-progress (ORDERED status) - should not corrupt completed average
    DB::table('lab_orders')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'created_by_user_id' => (string) $fx['user']->id,
        'order_number' => 'LAB-TAT-03',
        'priority' => 'ROUTINE',
        'status' => 'ORDERED',
        'occurred_at' => $baseTime,
        'recorded_at' => $baseTime,
        'created_at' => $baseTime,
        'updated_at' => $baseTime->addMinutes(10),
    ]);

    $builder = new LabTatProjectionBuilder;
    $processed = TenantContextScope::run($tenantId, $facilityId, fn () => $builder->rebuild($tenantId, $facilityId));

    expect($processed)->toBe(3);

    $tat = TenantContextScope::run($tenantId, $facilityId, fn () => LabTatDaily::query()
        ->where('tenant_id', $tenantId)
        ->where('facility_id', $facilityId)
        ->where('tat_date', '2026-09-10')
        ->first());

    expect($tat)->not->toBeNull();
    assert($tat instanceof LabTatDaily);
    expect($tat->total_tests_completed)->toBe(2);
    // Average of 60 and 180 minutes is 120.0 (NOT hardcoded 60.0)
    expect($tat->avg_tat_minutes)->toBe(120.0);
    // 1 test <= 120 min, 1 test > 120 min
    expect($tat->within_target_count)->toBe(1);
    expect($tat->breached_target_count)->toBe(1);
});
