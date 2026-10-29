<?php

declare(strict_types=1);

use App\Platform\Reporting\Domain\Model\DataMart;
use App\Platform\Reporting\Domain\Model\EncounterDailyAggregate;
use App\Platform\Reporting\Domain\Model\FinancialDailyRevenue;
use App\Platform\Reporting\Domain\Model\MetricDefinition;
use App\Platform\Reporting\Domain\Model\ProjectionCheckpoint;
use App\Platform\Reporting\Domain\Model\ReportDefinition;
use App\Platform\Reporting\Domain\Model\ReportRun;
use App\Platform\Reporting\Domain\Model\StatutoryReturn;
use App\Platform\Reporting\Domain\Model\StatutoryReturnLine;
use App\Platform\Reporting\Domain\ValueObject\ProjectionStreamStatus;
use App\Platform\Reporting\Domain\ValueObject\ReportCategory;
use App\Platform\Reporting\Domain\ValueObject\ReportRunStatus;
use App\Platform\Reporting\Domain\ValueObject\StatutoryFramework;
use App\Platform\Reporting\Domain\ValueObject\StatutoryReturnStatus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../../../../tests/Support/reporting.php';

it('strictly enforces PostgreSQL RLS across all reporting tables', function (): void {
    $fxA = reportingFixture();
    $tenantA = (string) $fxA['tenant']->id;
    $facilityA = (string) $fxA['facility']->id;

    $fxB = reportingFixture();
    $tenantB = (string) $fxB['tenant']->id;
    $facilityB = (string) $fxB['facility']->id;

    $now = CarbonImmutable::now();

    // Tenant A creates records in reporting tables
    TenantContextScope::run($tenantA, $facilityA, function () use ($tenantA, $facilityA, $now): void {
        ProjectionCheckpoint::create([
            'tenant_id' => $tenantA,
            'stream_name' => 'encounters',
            'checkpoint_offset' => 10,
            'status' => ProjectionStreamStatus::LIVE,
            'reference_config_version' => 'v1.0',
        ]);

        MetricDefinition::create([
            'tenant_id' => $tenantA,
            'code' => 'TEST_METRIC_A',
            'name' => 'Metric A',
            'owning_domain' => 'Clinical',
            'category' => ReportCategory::CLINICAL,
            'version' => 1,
            'status' => 'ACTIVE',
        ]);

        DataMart::create([
            'tenant_id' => $tenantA,
            'code' => 'mart_a',
            'name' => 'Mart A',
            'category' => ReportCategory::OPERATIONAL,
            'schema_definition' => ['columns' => ['id']],
            'is_curated' => true,
            'status' => 'ACTIVE',
        ]);

        ReportDefinition::create([
            'tenant_id' => $tenantA,
            'code' => 'RPT_A',
            'name' => 'Report A',
            'category' => ReportCategory::OPERATIONAL,
            'data_mart_code' => 'mart_a',
            'query_template' => [],
            'status' => 'ACTIVE',
        ]);

        ReportRun::create([
            'tenant_id' => $tenantA,
            'run_number' => 'RPT-2026-000001',
            'report_definition_code' => 'RPT_A',
            'status' => ReportRunStatus::COMPLETED,
            'as_of_time' => $now,
            'row_count' => 5,
        ]);

        $ret = StatutoryReturn::create([
            'tenant_id' => $tenantA,
            'return_number' => 'RET-2026-000001',
            'framework' => StatutoryFramework::HMIS_MTUHA,
            'period_type' => 'MONTHLY',
            'period_key' => '2026-11',
            'facility_id' => $facilityA,
            'reporting_unit_code' => 'FAC-001',
            'status' => StatutoryReturnStatus::GENERATED,
            'reference_config_version' => 'v1.0',
        ]);

        StatutoryReturnLine::create([
            'tenant_id' => $tenantA,
            'return_id' => $ret->id,
            'data_element_code' => 'ELEM_01',
            'value' => 42.0,
        ]);

        EncounterDailyAggregate::create([
            'tenant_id' => $tenantA,
            'facility_id' => $facilityA,
            'aggregate_date' => $now->toDateString(),
            'encounter_type' => 'OUTPATIENT',
            'total_encounters' => 15,
            'as_of_time' => $now,
        ]);

        FinancialDailyRevenue::create([
            'tenant_id' => $tenantA,
            'facility_id' => $facilityA,
            'revenue_date' => $now->toDateString(),
            'payment_method' => 'CASH',
            'total_billed' => 150000.0,
            'total_collected' => 150000.0,
            'as_of_time' => $now,
        ]);
    });

    // Tenant B queries - must see zero rows from Tenant A due to RLS
    TenantContextScope::run($tenantB, $facilityB, function (): void {
        expect(ProjectionCheckpoint::query()->where('stream_name', 'encounters')->first())->toBeNull()
            ->and(MetricDefinition::query()->where('code', 'TEST_METRIC_A')->first())->toBeNull()
            ->and(DataMart::query()->where('code', 'mart_a')->first())->toBeNull()
            ->and(ReportDefinition::query()->where('code', 'RPT_A')->first())->toBeNull()
            ->and(ReportRun::query()->where('run_number', 'RPT-2026-000001')->first())->toBeNull()
            ->and(StatutoryReturn::query()->where('return_number', 'RET-2026-000001')->first())->toBeNull()
            ->and(StatutoryReturnLine::query()->where('data_element_code', 'ELEM_01')->first())->toBeNull()
            ->and(EncounterDailyAggregate::query()->first())->toBeNull()
            ->and(FinancialDailyRevenue::query()->first())->toBeNull();
    });
});
