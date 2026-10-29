<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Infrastructure\Console;

use App\Platform\Reporting\Domain\Model\DataMart;
use App\Platform\Reporting\Domain\Model\MetricDefinition;
use App\Platform\Reporting\Domain\Model\ReportDefinition;
use App\Platform\Reporting\Domain\ValueObject\ReportCategory;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SeedReportingDefinitionsCommand extends Command
{
    protected $signature = 'reporting:seed-definitions
                            {--tenant= : Tenant UUID to seed definitions for}';

    protected $description = 'Seed standard metrics, curated data marts, and report definitions';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        $tenantIds = $tenantId !== null
            ? [(string) $tenantId]
            : (DB::getSchemaBuilder()->hasTable('org_tenants')
                ? DB::table('org_tenants')->pluck('id')->map(fn ($id) => (string) $id)->all()
                : []);

        if (empty($tenantIds)) {
            $this->warn('No tenants found or specified.');

            return self::SUCCESS;
        }

        foreach ($tenantIds as $tId) {
            $this->info("Seeding reporting definitions for tenant {$tId}...");

            TenantContextScope::run($tId, null, function () use ($tId): void {
                $this->seedMetrics($tId);
                $this->seedDataMarts($tId);
                $this->seedReportDefinitions($tId);
            });
        }

        $this->info('Reporting definitions seeded.');

        return self::SUCCESS;
    }

    private function seedMetrics(string $tenantId): void
    {
        $metrics = [
            [
                'code' => 'OPD_ATTENDANCE_DAILY',
                'name' => 'Daily Outpatient Attendance',
                'owning_domain' => 'Encounter',
                'category' => ReportCategory::OPERATIONAL,
                'description' => 'Total number of outpatient encounters recorded in a facility per day.',
                'unit_of_measure' => 'COUNT',
            ],
            [
                'code' => 'IPD_BED_OCCUPANCY_RATE',
                'name' => 'Inpatient Bed Occupancy Rate',
                'owning_domain' => 'BedWard',
                'category' => ReportCategory::CLINICAL,
                'description' => 'Percentage of operational beds occupied by admitted patients.',
                'unit_of_measure' => 'PERCENTAGE',
            ],
            [
                'code' => 'FIN_DAILY_COLLECTIONS',
                'name' => 'Total Daily Revenue Collected',
                'owning_domain' => 'Billing',
                'category' => ReportCategory::FINANCIAL,
                'description' => 'Total financial payments collected across all payment methods.',
                'unit_of_measure' => 'CURRENCY_TZS',
            ],
            [
                'code' => 'LAB_AVG_TAT_MINUTES',
                'name' => 'Laboratory Average Turnaround Time',
                'owning_domain' => 'Laboratory',
                'category' => ReportCategory::OPERATIONAL,
                'description' => 'Average elapsed minutes from specimen collection to result verification.',
                'unit_of_measure' => 'MINUTES',
            ],
        ];

        foreach ($metrics as $m) {
            MetricDefinition::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'code' => $m['code'],
                    'version' => 1,
                ],
                [
                    'name' => $m['name'],
                    'owning_domain' => $m['owning_domain'],
                    'category' => $m['category'],
                    'description' => $m['description'],
                    'unit_of_measure' => $m['unit_of_measure'],
                    'status' => 'ACTIVE',
                ]
            );
        }
    }

    private function seedDataMarts(string $tenantId): void
    {
        $marts = [
            [
                'code' => 'mart_encounters',
                'name' => 'Encounter Analytics Data Mart',
                'category' => ReportCategory::OPERATIONAL,
                'description' => 'Curated attendance, admission, discharge, and emergency aggregates.',
                'source_projections' => ['rpt_encounter_daily_aggregates'],
                'schema_definition' => [
                    'columns' => ['facility_id', 'aggregate_date', 'encounter_type', 'total_encounters', 'total_admissions', 'total_discharges', 'emergency_count'],
                    'pii_exposed' => false,
                ],
            ],
            [
                'code' => 'mart_revenue',
                'name' => 'Financial Revenue Data Mart',
                'category' => ReportCategory::FINANCIAL,
                'description' => 'Curated billed and collected revenue aggregates by payment method.',
                'source_projections' => ['rpt_financial_daily_revenue'],
                'schema_definition' => [
                    'columns' => ['facility_id', 'revenue_date', 'payment_method', 'total_billed', 'total_collected'],
                    'pii_exposed' => false,
                ],
            ],
            [
                'code' => 'mart_bed_occupancy',
                'name' => 'Bed Occupancy Data Mart',
                'category' => ReportCategory::CLINICAL,
                'description' => 'Curated ward and bed capacity and occupancy rates.',
                'source_projections' => ['rpt_bed_occupancy_daily'],
                'schema_definition' => [
                    'columns' => ['facility_id', 'ward_id', 'occupancy_date', 'total_beds', 'occupied_beds', 'occupancy_rate_percent'],
                    'pii_exposed' => false,
                ],
            ],
            [
                'code' => 'mart_lab_tat',
                'name' => 'Laboratory Turnaround Data Mart',
                'category' => ReportCategory::OPERATIONAL,
                'description' => 'Curated test volume, turnaround times, and SLA breach metrics.',
                'source_projections' => ['rpt_lab_tat_daily'],
                'schema_definition' => [
                    'columns' => ['facility_id', 'tat_date', 'test_category', 'total_tests_completed', 'avg_tat_minutes', 'within_target_count', 'breached_target_count'],
                    'pii_exposed' => false,
                ],
            ],
        ];

        foreach ($marts as $mart) {
            DataMart::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'code' => $mart['code'],
                ],
                [
                    'name' => $mart['name'],
                    'category' => $mart['category'],
                    'description' => $mart['description'],
                    'source_projections' => $mart['source_projections'],
                    'schema_definition' => $mart['schema_definition'],
                    'is_curated' => true,
                    'status' => 'ACTIVE',
                ]
            );
        }
    }

    private function seedReportDefinitions(string $tenantId): void
    {
        $reports = [
            [
                'code' => 'RPT_DAILY_ATTENDANCE_SUMMARY',
                'name' => 'Daily Attendance Summary Report',
                'category' => ReportCategory::OPERATIONAL,
                'data_mart_code' => 'mart_encounters',
                'description' => 'Summary of patient attendances across all facility clinics.',
                'query_template' => ['group_by' => ['encounter_type'], 'metrics' => ['total_encounters']],
            ],
            [
                'code' => 'RPT_DAILY_CASHIER_REVENUE',
                'name' => 'Daily Revenue by Payment Method',
                'category' => ReportCategory::FINANCIAL,
                'data_mart_code' => 'mart_revenue',
                'description' => 'Collection totals broken down by payment instrument.',
                'query_template' => ['group_by' => ['payment_method'], 'metrics' => ['total_collected']],
            ],
            [
                'code' => 'RPT_WARD_BED_UTILIZATION',
                'name' => 'Ward Bed Utilization Report',
                'category' => ReportCategory::CLINICAL,
                'data_mart_code' => 'mart_bed_occupancy',
                'description' => 'Bed census and occupancy percentage across wards.',
                'query_template' => ['group_by' => ['ward_id'], 'metrics' => ['occupied_beds', 'occupancy_rate_percent']],
            ],
        ];

        foreach ($reports as $r) {
            ReportDefinition::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'code' => $r['code'],
                ],
                [
                    'name' => $r['name'],
                    'category' => $r['category'],
                    'data_mart_code' => $r['data_mart_code'],
                    'description' => $r['description'],
                    'query_template' => $r['query_template'],
                    'status' => 'ACTIVE',
                ]
            );
        }
    }
}
