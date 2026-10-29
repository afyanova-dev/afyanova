<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Reporting;

use App\Platform\Reporting\Domain\Model\EncounterDailyAggregate;
use App\Platform\Reporting\Infrastructure\Projection\EncounterProjectionBuilder;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/reporting.php';

test('EncounterProjectionBuilder computes actual average duration in minutes from encounter timestamps', function (): void {
    $fx = reportingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $baseTime = CarbonImmutable::parse('2026-09-10 08:00:00');

    // Encounter 1: 90 minutes duration (08:00 to 09:30)
    DB::table('enc_encounters')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => (string) Str::uuid7(),
        'created_by_user_id' => (string) $fx['user']->id,
        'encounter_number' => 'ENC-DUR-01',
        'encounter_type' => 'OUTPATIENT',
        'status' => 'COMPLETED',
        'arrived_at' => $baseTime,
        'started_at' => $baseTime->addMinutes(15),
        'completed_at' => $baseTime->addMinutes(90),
        'created_at' => $baseTime,
        'updated_at' => $baseTime->addMinutes(90),
    ]);

    // Encounter 2: 30 minutes duration (08:30 to 09:00)
    DB::table('enc_encounters')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => (string) Str::uuid7(),
        'created_by_user_id' => (string) $fx['user']->id,
        'encounter_number' => 'ENC-DUR-02',
        'encounter_type' => 'OUTPATIENT',
        'status' => 'COMPLETED',
        'arrived_at' => $baseTime->addMinutes(30),
        'started_at' => $baseTime->addMinutes(35),
        'completed_at' => $baseTime->addMinutes(60),
        'created_at' => $baseTime->addMinutes(30),
        'updated_at' => $baseTime->addMinutes(60),
    ]);

    // Encounter 3: in-progress (arrived, but completed_at is null) - should not corrupt average
    DB::table('enc_encounters')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => (string) Str::uuid7(),
        'created_by_user_id' => (string) $fx['user']->id,
        'encounter_number' => 'ENC-DUR-03',
        'encounter_type' => 'OUTPATIENT',
        'status' => 'ACTIVE',
        'arrived_at' => $baseTime->addMinutes(45),
        'started_at' => $baseTime->addMinutes(50),
        'completed_at' => null,
        'created_at' => $baseTime->addMinutes(45),
        'updated_at' => $baseTime->addMinutes(50),
    ]);

    $builder = new EncounterProjectionBuilder;
    $processed = TenantContextScope::run($tenantId, $facilityId, fn () => $builder->rebuild($tenantId, $facilityId));

    expect($processed)->toBe(3);

    $aggregate = TenantContextScope::run($tenantId, $facilityId, fn () => EncounterDailyAggregate::query()
        ->where('tenant_id', $tenantId)
        ->where('facility_id', $facilityId)
        ->where('aggregate_date', '2026-09-10')
        ->first());

    expect($aggregate)->not->toBeNull();
    expect($aggregate->total_encounters)->toBe(3);
    expect($aggregate->total_discharges)->toBe(2);
    // Average of 90 and 30 minutes is precisely 60.0 (NOT the hardcoded 45.0)
    expect($aggregate->avg_duration_minutes)->toBe(60.0);
});
