<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

use App\Platform\Reporting\Domain\Exception\DataMartNotFound;
use App\Platform\Reporting\Domain\Exception\UnauthorizedMartAccess;
use App\Platform\Reporting\Domain\Model\BedOccupancyDaily;
use App\Platform\Reporting\Domain\Model\DataMart;
use App\Platform\Reporting\Domain\Model\EncounterDailyAggregate;
use App\Platform\Reporting\Domain\Model\FinancialDailyRevenue;
use App\Platform\Reporting\Domain\Model\LabTatDaily;
use App\Platform\Reporting\Domain\Model\PharmacyDailyAggregate;

final class QueryDataMartHandler
{
    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(QueryDataMartQuery $query): array
    {
        /** @var DataMart|null $mart */
        $mart = DataMart::query()
            ->where('tenant_id', $query->tenantId)
            ->where('code', $query->dataMartCode)
            ->first();

        if ($mart === null) {
            throw new DataMartNotFound("Data mart '{$query->dataMartCode}' not found.");
        }

        if (! $mart->is_curated || $mart->status !== 'ACTIVE') {
            throw new UnauthorizedMartAccess("Data mart '{$query->dataMartCode}' is not an approved curated mart (§7.7).");
        }

        $facilityId = $query->filters['facility_id'] ?? null;
        $date = $query->filters['date'] ?? null;
        $limit = min($query->limit, 1000);

        /** @var list<array<string, mixed>> $rows */
        $rows = match ($mart->code) {
            'mart_encounters' => array_values(EncounterDailyAggregate::query()
                ->where('tenant_id', $query->tenantId)
                ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
                ->when($date !== null, fn ($q) => $q->where('aggregate_date', $date))
                ->limit($limit)
                ->get()
                ->toArray()),

            'mart_revenue' => array_values(FinancialDailyRevenue::query()
                ->where('tenant_id', $query->tenantId)
                ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
                ->when($date !== null, fn ($q) => $q->where('revenue_date', $date))
                ->limit($limit)
                ->get()
                ->toArray()),

            'mart_bed_occupancy' => array_values(BedOccupancyDaily::query()
                ->where('tenant_id', $query->tenantId)
                ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
                ->when($date !== null, fn ($q) => $q->where('occupancy_date', $date))
                ->limit($limit)
                ->get()
                ->toArray()),

            'mart_lab_tat' => array_values(LabTatDaily::query()
                ->where('tenant_id', $query->tenantId)
                ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
                ->when($date !== null, fn ($q) => $q->where('tat_date', $date))
                ->limit($limit)
                ->get()
                ->toArray()),

            'mart_pharmacy' => array_values(PharmacyDailyAggregate::query()
                ->where('tenant_id', $query->tenantId)
                ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
                ->when($date !== null, fn ($q) => $q->where('dispense_date', $date))
                ->limit($limit)
                ->get()
                ->toArray()),

            default => [],
        };

        return $rows;
    }
}
