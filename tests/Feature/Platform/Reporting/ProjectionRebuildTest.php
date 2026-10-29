<?php

declare(strict_types=1);

use App\Platform\Reporting\Application\Command\RebuildProjectionCommand;
use App\Platform\Reporting\Contracts\ReportingApi;
use App\Platform\Reporting\Domain\Model\EncounterDailyAggregate;
use App\Platform\Reporting\Domain\Model\FinancialDailyRevenue;
use App\Platform\Reporting\Domain\Model\PharmacyDailyAggregate;
use App\Platform\Reporting\Domain\ValueObject\ProjectionStreamStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../../../../tests/Support/reporting.php';

it('rebuilds projections deterministically and exposes dashboard KPIs via ReportingApi', function (): void {
    $fx = reportingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $commandBus = app(CommandBus::class);
    $api = app(ReportingApi::class);
    $today = CarbonImmutable::now()->toDateString();

    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus, $api, $tenantId, $facilityId, $today): void {
        // 1. Dispatch rebuild command
        $checkpoint = $commandBus->dispatch(new RebuildProjectionCommand(
            tenantId: $tenantId,
            streamName: 'encounters',
            facilityId: $facilityId,
        ));

        expect($checkpoint->status)->toBe(ProjectionStreamStatus::LIVE)
            ->and($checkpoint->last_rebuilt_at)->not->toBeNull();

        // 2. Add projection aggregate records
        EncounterDailyAggregate::create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'aggregate_date' => $today,
            'encounter_type' => 'OUTPATIENT',
            'total_encounters' => 50,
            'total_admissions' => 10,
            'total_discharges' => 8,
            'emergency_count' => 4,
            'avg_duration_minutes' => 30.0,
            'as_of_time' => now(),
        ]);

        FinancialDailyRevenue::create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'revenue_date' => $today,
            'payment_method' => 'CASH',
            'total_billed' => 250000.0,
            'total_collected' => 250000.0,
            'as_of_time' => now(),
        ]);

        PharmacyDailyAggregate::create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'dispense_date' => $today,
            'total_prescriptions' => 45,
            'total_active' => 38,
            'total_cancelled' => 7,
            'as_of_time' => now(),
        ]);

        // 3. Query through ReportingApi
        $kpis = $api->getDashboardKpis($facilityId, $today);

        expect($kpis->facilityId)->toBe($facilityId)
            ->and($kpis->encounters['total'])->toBe(50)
            ->and($kpis->encounters['admissions'])->toBe(10)
            ->and($kpis->financials['total_collected'])->toBe(250000.0)
            ->and($kpis->pharmacy)->not->toBeNull()
            ->and($kpis->pharmacy['total_prescriptions'])->toBe(45)
            ->and($kpis->pharmacy['total_active'])->toBe(38)
            ->and($kpis->pharmacy['total_cancelled'])->toBe(7);

        // 4. Query projection health through ReportingApi
        $health = $api->getProjectionHealth('encounters');
        expect($health)->not->toBeNull();
        assert($health !== null);
        expect($health->streamName)->toBe('encounters')
            ->and($health->status)->toBe('LIVE');
    });
});
