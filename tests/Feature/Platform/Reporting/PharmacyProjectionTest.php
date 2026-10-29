<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Reporting;

use App\Platform\Reporting\Application\Command\RebuildProjectionCommand;
use App\Platform\Reporting\Domain\Model\PharmacyDailyAggregate;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/reporting.php';

test('PharmacyProjectionBuilder aggregates daily prescriptions and rebuilds via command bus', function (): void {
    $fx = reportingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $commandBus = app(CommandBus::class);
    $dispenseDate = CarbonImmutable::parse('2026-09-11 10:00:00');

    // Seed 2 active and 1 cancelled prescription in rx_prescriptions
    DB::table('rx_prescriptions')->insert([
        [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'prescribing_decision_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'prescription_number' => 'RX-2026-0001',
            'author_user_id' => (string) $fx['user']->id,
            'status' => 'ACTIVE',
            'occurred_at' => $dispenseDate,
            'recorded_at' => $dispenseDate,
            'created_at' => $dispenseDate,
            'updated_at' => $dispenseDate,
        ],
        [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'prescribing_decision_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'prescription_number' => 'RX-2026-0002',
            'author_user_id' => (string) $fx['user']->id,
            'status' => 'ACTIVE',
            'occurred_at' => $dispenseDate->addHours(1),
            'recorded_at' => $dispenseDate->addHours(1),
            'created_at' => $dispenseDate->addHours(1),
            'updated_at' => $dispenseDate->addHours(1),
        ],
        [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'prescribing_decision_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'prescription_number' => 'RX-2026-0003',
            'author_user_id' => (string) $fx['user']->id,
            'status' => 'CANCELLED',
            'occurred_at' => $dispenseDate->addHours(2),
            'recorded_at' => $dispenseDate->addHours(2),
            'created_at' => $dispenseDate->addHours(2),
            'updated_at' => $dispenseDate->addHours(2),
        ],
    ]);

    // Dispatch RebuildProjectionCommand for pharmacy stream
    $checkpoint = TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new RebuildProjectionCommand(
        tenantId: $tenantId,
        streamName: 'pharmacy',
        facilityId: $facilityId,
    )));

    expect($checkpoint)->not->toBeNull();
    expect($checkpoint->checkpoint_offset)->toBe(3);

    $aggregate = TenantContextScope::run($tenantId, $facilityId, fn () => PharmacyDailyAggregate::query()
        ->where('tenant_id', $tenantId)
        ->where('facility_id', $facilityId)
        ->where('dispense_date', '2026-09-11')
        ->first());

    expect($aggregate)->not->toBeNull();
    expect($aggregate->total_prescriptions)->toBe(3);
    expect($aggregate->total_active)->toBe(2);
    expect($aggregate->total_cancelled)->toBe(1);
});
