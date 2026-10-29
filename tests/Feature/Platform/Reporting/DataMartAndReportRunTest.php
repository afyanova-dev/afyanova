<?php

declare(strict_types=1);

use App\Platform\Reporting\Application\Command\DefineReportDefinitionCommand;
use App\Platform\Reporting\Application\Command\RunReportCommand;
use App\Platform\Reporting\Application\Query\QueryDataMartQuery;
use App\Platform\Reporting\Domain\Exception\UnauthorizedMartAccess;
use App\Platform\Reporting\Domain\Model\DataMart;
use App\Platform\Reporting\Domain\Model\EncounterDailyAggregate;
use App\Platform\Reporting\Domain\ValueObject\ReportCategory;
use App\Platform\Reporting\Domain\ValueObject\ReportRunStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../../../../tests/Support/reporting.php';

it('governs self-service access strictly to curated, approved data marts per Invariant 7.7', function (): void {
    $fx = reportingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $queryBus = app(QueryBus::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($queryBus, $tenantId): void {
        // Create an uncurated mart
        DataMart::create([
            'tenant_id' => $tenantId,
            'code' => 'mart_raw_shadow',
            'name' => 'Shadow Uncurated Mart',
            'category' => ReportCategory::CUSTOM,
            'schema_definition' => ['columns' => ['pii_field']],
            'is_curated' => false,
            'status' => 'ACTIVE',
        ]);

        expect(fn () => $queryBus->dispatch(new QueryDataMartQuery(
            tenantId: $tenantId,
            dataMartCode: 'mart_raw_shadow',
        )))->toThrow(UnauthorizedMartAccess::class);
    });
});

it('executes a report run against an approved data mart', function (): void {
    $fx = reportingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $commandBus = app(CommandBus::class);
    $now = CarbonImmutable::now();

    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus, $tenantId, $facilityId, $userId, $now): void {
        // Seed aggregate data
        EncounterDailyAggregate::create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'aggregate_date' => $now->toDateString(),
            'encounter_type' => 'OUTPATIENT',
            'total_encounters' => 25,
            'total_admissions' => 5,
            'total_discharges' => 3,
            'emergency_count' => 2,
            'avg_duration_minutes' => 35.0,
            'as_of_time' => $now,
        ]);

        // Define report
        $commandBus->dispatch(new DefineReportDefinitionCommand(
            tenantId: $tenantId,
            code: 'RPT_DAILY_ATTENDANCE',
            name: 'Daily Attendance Report',
            category: ReportCategory::OPERATIONAL,
            dataMartCode: 'mart_encounters',
            queryTemplate: ['filters' => ['encounter_type' => 'OUTPATIENT']],
        ));

        // Execute report run
        $run = $commandBus->dispatch(new RunReportCommand(
            tenantId: $tenantId,
            reportDefinitionCode: 'RPT_DAILY_ATTENDANCE',
            parameters: ['facility_id' => $facilityId],
            requestedBy: $userId,
            facilityId: $facilityId,
        ));

        expect($run->status)->toBe(ReportRunStatus::COMPLETED)
            ->and($run->row_count)->toBeGreaterThan(0)
            ->and($run->run_number)->toStartWith('RPT-')
            ->and($run->result_dataset)->toHaveKey('rows');
    });
});
