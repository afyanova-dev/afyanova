<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Infrastructure\Projection;

use App\Platform\Reporting\Domain\Model\LabTatDaily;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class LabTatProjectionBuilder
{
    public function rebuild(string $tenantId, ?string $facilityId = null): int
    {
        $now = CarbonImmutable::now();
        $schema = DB::getSchemaBuilder();

        if ($schema->hasTable('lab_results')) {
            $processed = $this->rebuildFromResults($tenantId, $facilityId, $now);
            if ($processed > 0) {
                return $processed;
            }
        }

        if ($schema->hasTable('lab_orders')) {
            return $this->rebuildFromOrders($tenantId, $facilityId, $now);
        }

        return 0;
    }

    private function rebuildFromResults(string $tenantId, ?string $facilityId, CarbonImmutable $now): int
    {
        $query = DB::table('lab_results')
            ->where('tenant_id', $tenantId)
            ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
            ->selectRaw("
                facility_id,
                COALESCE(DATE(created_at), CURRENT_DATE) as tat_date,
                COUNT(*) as total_tests,
                COUNT(CASE WHEN status IN ('VERIFIED', 'RELEASED') THEN 1 END) as verified_tests,
                COALESCE(
                    ROUND(
                        AVG(
                            CASE 
                                WHEN verified_at IS NOT NULL AND (occurred_at IS NOT NULL OR created_at IS NOT NULL)
                                THEN EXTRACT(EPOCH FROM (verified_at - COALESCE(occurred_at, created_at))) / 60.0
                                WHEN status IN ('VERIFIED', 'RELEASED') AND updated_at IS NOT NULL AND created_at IS NOT NULL
                                THEN EXTRACT(EPOCH FROM (updated_at - created_at)) / 60.0
                                ELSE NULL
                            END
                        )::numeric,
                        1
                    ),
                    0.0
                ) as avg_tat,
                COUNT(
                    CASE 
                        WHEN verified_at IS NOT NULL AND (occurred_at IS NOT NULL OR created_at IS NOT NULL)
                            AND (EXTRACT(EPOCH FROM (verified_at - COALESCE(occurred_at, created_at))) / 60.0) <= 120.0
                        THEN 1
                        WHEN status IN ('VERIFIED', 'RELEASED') 
                            AND updated_at IS NOT NULL AND created_at IS NOT NULL
                            AND (EXTRACT(EPOCH FROM (updated_at - created_at)) / 60.0) <= 120.0
                        THEN 1
                    END
                ) as within_target,
                COUNT(
                    CASE 
                        WHEN verified_at IS NOT NULL AND (occurred_at IS NOT NULL OR created_at IS NOT NULL)
                            AND (EXTRACT(EPOCH FROM (verified_at - COALESCE(occurred_at, created_at))) / 60.0) > 120.0
                        THEN 1
                        WHEN status IN ('VERIFIED', 'RELEASED') 
                            AND updated_at IS NOT NULL AND created_at IS NOT NULL
                            AND (EXTRACT(EPOCH FROM (updated_at - created_at)) / 60.0) > 120.0
                        THEN 1
                    END
                ) as breached_target
            ")
            ->groupByRaw('facility_id, COALESCE(DATE(created_at), CURRENT_DATE)');

        $rows = $query->get();
        $processed = 0;

        foreach ($rows as $row) {
            $total = (int) $row->total_tests;
            $verified = (int) $row->verified_tests;
            $avgTat = (float) $row->avg_tat;
            $within = (int) $row->within_target;
            $breached = (int) $row->breached_target;

            LabTatDaily::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'facility_id' => (string) $row->facility_id,
                    'tat_date' => (string) $row->tat_date,
                    'test_category' => 'GENERAL',
                ],
                [
                    'total_tests_completed' => $verified,
                    'avg_tat_minutes' => $avgTat,
                    'within_target_count' => $within,
                    'breached_target_count' => $breached,
                    'as_of_time' => $now,
                ]
            );
            $processed += $total;
        }

        return $processed;
    }

    private function rebuildFromOrders(string $tenantId, ?string $facilityId, CarbonImmutable $now): int
    {
        $query = DB::table('lab_orders')
            ->where('tenant_id', $tenantId)
            ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
            ->selectRaw("
                facility_id,
                COALESCE(DATE(created_at), CURRENT_DATE) as tat_date,
                COUNT(*) as total_tests,
                COUNT(CASE WHEN status IN ('VERIFIED', 'ACCEPTED', 'COMPLETED') THEN 1 END) as verified_tests,
                COALESCE(
                    ROUND(
                        AVG(
                            CASE 
                                WHEN status IN ('VERIFIED', 'ACCEPTED', 'COMPLETED') AND updated_at IS NOT NULL AND created_at IS NOT NULL
                                THEN EXTRACT(EPOCH FROM (updated_at - created_at)) / 60.0
                                ELSE NULL
                            END
                        )::numeric,
                        1
                    ),
                    0.0
                ) as avg_tat,
                COUNT(
                    CASE 
                        WHEN status IN ('VERIFIED', 'ACCEPTED', 'COMPLETED') 
                            AND updated_at IS NOT NULL AND created_at IS NOT NULL
                            AND (EXTRACT(EPOCH FROM (updated_at - created_at)) / 60.0) <= 120.0
                        THEN 1
                    END
                ) as within_target,
                COUNT(
                    CASE 
                        WHEN status IN ('VERIFIED', 'ACCEPTED', 'COMPLETED') 
                            AND updated_at IS NOT NULL AND created_at IS NOT NULL
                            AND (EXTRACT(EPOCH FROM (updated_at - created_at)) / 60.0) > 120.0
                        THEN 1
                    END
                ) as breached_target
            ")
            ->groupByRaw('facility_id, COALESCE(DATE(created_at), CURRENT_DATE)');

        $rows = $query->get();
        $processed = 0;

        foreach ($rows as $row) {
            $total = (int) $row->total_tests;
            $verified = (int) $row->verified_tests;
            $avgTat = (float) $row->avg_tat;
            $within = (int) $row->within_target;
            $breached = (int) $row->breached_target;

            LabTatDaily::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'facility_id' => (string) $row->facility_id,
                    'tat_date' => (string) $row->tat_date,
                    'test_category' => 'GENERAL',
                ],
                [
                    'total_tests_completed' => $verified,
                    'avg_tat_minutes' => $avgTat,
                    'within_target_count' => $within,
                    'breached_target_count' => $breached,
                    'as_of_time' => $now,
                ]
            );
            $processed += $total;
        }

        return $processed;
    }
}
