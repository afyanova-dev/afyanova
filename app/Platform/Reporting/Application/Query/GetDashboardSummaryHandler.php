<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

use App\Platform\Reporting\Domain\Model\BedOccupancyDaily;
use App\Platform\Reporting\Domain\Model\EncounterDailyAggregate;
use App\Platform\Reporting\Domain\Model\FinancialDailyRevenue;
use App\Platform\Reporting\Domain\Model\LabTatDaily;
use App\Platform\Reporting\Domain\Model\PharmacyDailyAggregate;
use Carbon\CarbonImmutable;

final class GetDashboardSummaryHandler
{
    /**
     * @return array{
     *     facility_id: string,
     *     as_of_date: string,
     *     encounters: array{total: int, admissions: int, discharges: int, emergencies: int},
     *     financials: array{total_billed: float, total_collected: float},
     *     bed_occupancy: array{total_beds: int, occupied_beds: int, rate_percent: float},
     *     lab: array{tests_completed: int, avg_tat_minutes: float},
     *     pharmacy: array{total_prescriptions: int, total_active: int, total_cancelled: int}
     * }
     */
    public function __invoke(GetDashboardSummaryQuery $query): array
    {
        $date = $query->date ?? CarbonImmutable::now()->toDateString();

        $encAgg = EncounterDailyAggregate::query()
            ->where('tenant_id', $query->tenantId)
            ->where('facility_id', $query->facilityId)
            ->where('aggregate_date', $date)
            ->get();

        $totalEnc = (int) $encAgg->sum('total_encounters');
        $totalAdm = (int) $encAgg->sum('total_admissions');
        $totalDis = (int) $encAgg->sum('total_discharges');
        $totalEmg = (int) $encAgg->sum('emergency_count');

        $finAgg = FinancialDailyRevenue::query()
            ->where('tenant_id', $query->tenantId)
            ->where('facility_id', $query->facilityId)
            ->where('revenue_date', $date)
            ->get();

        $totalBilled = (float) $finAgg->sum('total_billed');
        $totalCollected = (float) $finAgg->sum('total_collected');

        $bedAgg = BedOccupancyDaily::query()
            ->where('tenant_id', $query->tenantId)
            ->where('facility_id', $query->facilityId)
            ->where('occupancy_date', $date)
            ->get();

        $totalBeds = (int) $bedAgg->sum('total_beds');
        $occupiedBeds = (int) $bedAgg->sum('occupied_beds');
        $ratePercent = $totalBeds > 0 ? round(($occupiedBeds / $totalBeds) * 100, 2) : 0.0;

        $labAgg = LabTatDaily::query()
            ->where('tenant_id', $query->tenantId)
            ->where('facility_id', $query->facilityId)
            ->where('tat_date', $date)
            ->get();

        $totalTests = (int) $labAgg->sum('total_tests_completed');
        $avgTat = (float) ($labAgg->avg('avg_tat_minutes') ?? 0.0);

        $pharmAgg = PharmacyDailyAggregate::query()
            ->where('tenant_id', $query->tenantId)
            ->where('facility_id', $query->facilityId)
            ->where('dispense_date', $date)
            ->get();

        $totalRx = (int) $pharmAgg->sum('total_prescriptions');
        $activeRx = (int) $pharmAgg->sum('total_active');
        $cancelledRx = (int) $pharmAgg->sum('total_cancelled');

        return [
            'facility_id' => $query->facilityId,
            'as_of_date' => $date,
            'encounters' => [
                'total' => $totalEnc,
                'admissions' => $totalAdm,
                'discharges' => $totalDis,
                'emergencies' => $totalEmg,
            ],
            'financials' => [
                'total_billed' => $totalBilled,
                'total_collected' => $totalCollected,
            ],
            'bed_occupancy' => [
                'total_beds' => $totalBeds,
                'occupied_beds' => $occupiedBeds,
                'rate_percent' => $ratePercent,
            ],
            'lab' => [
                'tests_completed' => $totalTests,
                'avg_tat_minutes' => $avgTat,
            ],
            'pharmacy' => [
                'total_prescriptions' => $totalRx,
                'total_active' => $activeRx,
                'total_cancelled' => $cancelledRx,
            ],
        ];
    }
}
