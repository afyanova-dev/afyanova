<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Infrastructure\Projection;

use App\Platform\Reporting\Domain\Model\PharmacyDailyAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PharmacyProjectionBuilder
{
    public function rebuild(string $tenantId, ?string $facilityId = null): int
    {
        $now = CarbonImmutable::now();

        if (! DB::getSchemaBuilder()->hasTable('rx_prescriptions')) {
            return 0;
        }

        $query = DB::table('rx_prescriptions')
            ->where('tenant_id', $tenantId)
            ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
            ->selectRaw("
                facility_id,
                COALESCE(DATE(occurred_at), CURRENT_DATE) as dispense_date,
                COUNT(*) as total_rx,
                COUNT(CASE WHEN status = 'ACTIVE' THEN 1 END) as active_rx,
                COUNT(CASE WHEN status = 'CANCELLED' THEN 1 END) as cancelled_rx
            ")
            ->groupByRaw('facility_id, COALESCE(DATE(occurred_at), CURRENT_DATE)');

        $rows = $query->get();
        $processed = 0;

        foreach ($rows as $row) {
            $total = (int) $row->total_rx;

            PharmacyDailyAggregate::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'facility_id' => (string) $row->facility_id,
                    'dispense_date' => (string) $row->dispense_date,
                ],
                [
                    'total_prescriptions' => $total,
                    'total_active' => (int) $row->active_rx,
                    'total_cancelled' => (int) $row->cancelled_rx,
                    'as_of_time' => $now,
                ]
            );

            $processed += $total;
        }

        return $processed;
    }
}
