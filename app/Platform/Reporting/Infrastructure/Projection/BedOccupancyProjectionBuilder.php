<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Infrastructure\Projection;

use App\Platform\Reporting\Domain\Model\BedOccupancyDaily;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BedOccupancyProjectionBuilder
{
    public function rebuild(string $tenantId, ?string $facilityId = null): int
    {
        $now = CarbonImmutable::now();

        if (! DB::getSchemaBuilder()->hasTable('bw_beds')) {
            return 0;
        }

        $query = DB::table('bw_beds')
            ->where('tenant_id', $tenantId)
            ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
            ->selectRaw('
                facility_id,
                ward_id,
                COUNT(*) as total_beds,
                COUNT(CASE WHEN status = \'OCCUPIED\' THEN 1 END) as occupied_beds
            ')
            ->groupBy(['facility_id', 'ward_id']);

        $rows = $query->get();
        $processed = 0;

        foreach ($rows as $row) {
            $total = (int) $row->total_beds;
            $occupied = (int) $row->occupied_beds;
            $rate = $total > 0 ? round(($occupied / $total) * 100, 2) : 0.0;

            $admissions = 0;
            $discharges = 0;

            if (DB::getSchemaBuilder()->hasTable('bw_bed_occupancies')) {
                $occRow = DB::table('bw_bed_occupancies')
                    ->join('bw_beds', 'bw_bed_occupancies.bed_id', '=', 'bw_beds.id')
                    ->where('bw_bed_occupancies.tenant_id', $tenantId)
                    ->where('bw_bed_occupancies.facility_id', (string) $row->facility_id)
                    ->when($row->ward_id !== null, fn ($q) => $q->where('bw_beds.ward_id', (string) $row->ward_id))
                    ->selectRaw('
                        COUNT(CASE WHEN DATE(bw_bed_occupancies.occurred_at) = CURRENT_DATE THEN 1 END) as admissions,
                        COUNT(CASE WHEN DATE(bw_bed_occupancies.ended_at) = CURRENT_DATE THEN 1 END) as discharges
                    ')
                    ->first();

                if ($occRow !== null) {
                    $admissions = (int) $occRow->admissions;
                    $discharges = (int) $occRow->discharges;
                }
            }

            BedOccupancyDaily::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'facility_id' => (string) $row->facility_id,
                    'ward_id' => $row->ward_id !== null ? (string) $row->ward_id : null,
                    'occupancy_date' => $now->toDateString(),
                ],
                [
                    'total_beds' => $total,
                    'occupied_beds' => $occupied,
                    'occupancy_rate_percent' => $rate,
                    'admissions_count' => $admissions,
                    'discharges_count' => $discharges,
                    'as_of_time' => $now,
                ]
            );
            $processed += $total;
        }

        return $processed;
    }
}
