<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Infrastructure\Projection;

use App\Platform\Reporting\Domain\Model\EncounterDailyAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class EncounterProjectionBuilder
{
    public function rebuild(string $tenantId, ?string $facilityId = null): int
    {
        $now = CarbonImmutable::now();

        if (! DB::getSchemaBuilder()->hasTable('enc_encounters')) {
            return 0;
        }

        $query = DB::table('enc_encounters')
            ->where('tenant_id', $tenantId)
            ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
            ->selectRaw("
                facility_id,
                COALESCE(DATE(arrived_at), CURRENT_DATE) as agg_date,
                COALESCE(encounter_type, 'OUTPATIENT') as enc_type,
                COUNT(*) as total_enc,
                COUNT(CASE WHEN admitted_at IS NOT NULL THEN 1 END) as total_adm,
                COUNT(CASE WHEN completed_at IS NOT NULL THEN 1 END) as total_dis,
                COUNT(CASE WHEN encounter_type = 'EMERGENCY' THEN 1 END) as emerg_cnt,
                COALESCE(
                    ROUND(
                        AVG(
                            CASE 
                                WHEN completed_at IS NOT NULL AND arrived_at IS NOT NULL 
                                THEN EXTRACT(EPOCH FROM (completed_at - arrived_at)) / 60.0 
                                ELSE NULL 
                            END
                        )::numeric, 
                        1
                    ), 
                    0.0
                ) as avg_duration
            ")
            ->groupByRaw("facility_id, COALESCE(DATE(arrived_at), CURRENT_DATE), COALESCE(encounter_type, 'OUTPATIENT')");

        $rows = $query->get();
        $processed = 0;

        foreach ($rows as $row) {
            EncounterDailyAggregate::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'facility_id' => (string) $row->facility_id,
                    'aggregate_date' => (string) $row->agg_date,
                    'encounter_type' => (string) $row->enc_type,
                ],
                [
                    'total_encounters' => (int) $row->total_enc,
                    'total_admissions' => (int) $row->total_adm,
                    'total_discharges' => (int) $row->total_dis,
                    'emergency_count' => (int) $row->emerg_cnt,
                    'avg_duration_minutes' => (float) $row->avg_duration,
                    'as_of_time' => $now,
                ]
            );
            $processed += (int) $row->total_enc;
        }

        return $processed;
    }
}
