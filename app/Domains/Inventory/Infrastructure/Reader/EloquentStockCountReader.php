<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Reader;

use App\Domains\Inventory\Contracts\Dto\StockCountLineSummary;
use App\Domains\Inventory\Contracts\Dto\StockCountSummary;
use App\Domains\Inventory\Domain\Model\StockCount;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\StockCountReader;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class EloquentStockCountReader implements StockCountReader
{
    public function __construct(private readonly TenantContext $context) {}

    public function recentForFacility(string $facilityId, int $limit = 25): array
    {
        $limit = max(1, min($limit, 100));

        // One joined read for the screen. The tenant filter is explicit here
        // because the query does not start from the model; RLS still enforces
        // it at the database.
        $rows = DB::table('inv_stock_counts')
            ->join('inv_stock_locations', 'inv_stock_locations.id', '=', 'inv_stock_counts.location_id')
            ->where('inv_stock_counts.tenant_id', $this->context->tenantId())
            ->where('inv_stock_counts.facility_id', $facilityId)
            ->orderByDesc('inv_stock_counts.started_at')
            ->orderByDesc('inv_stock_counts.count_number')
            ->limit($limit)
            ->get($this->headerColumns());

        $countIds = [];
        foreach ($rows as $row) {
            $countIds[] = (string) $row->count_id;
        }

        $stats = $this->lineStats($countIds);

        return array_values($rows->map(fn (object $row): StockCountSummary => $this->toSummary(
            (array) $row,
            $stats[(string) $row->count_id] ?? ['lines' => 0, 'counted' => 0, 'variance' => 0],
        ))->all());
    }

    public function summary(string $stockCountId): StockCountSummary
    {
        $row = DB::table('inv_stock_counts')
            ->join('inv_stock_locations', 'inv_stock_locations.id', '=', 'inv_stock_counts.location_id')
            ->where('inv_stock_counts.tenant_id', $this->context->tenantId())
            ->where('inv_stock_counts.id', $stockCountId)
            ->first($this->headerColumns());

        if ($row === null) {
            throw NotFound::aggregate(StockCount::class, $stockCountId);
        }

        $stats = $this->lineStats([$stockCountId]);

        return $this->toSummary((array) $row, $stats[$stockCountId] ?? ['lines' => 0, 'counted' => 0, 'variance' => 0]);
    }

    public function linesFor(string $stockCountId): array
    {
        $rows = DB::table('inv_stock_count_lines')
            ->join('inv_items', 'inv_items.id', '=', 'inv_stock_count_lines.item_id')
            ->leftJoin('inv_batches', 'inv_batches.id', '=', 'inv_stock_count_lines.batch_id')
            ->leftJoin('inv_stock_adjustments', 'inv_stock_adjustments.id', '=', 'inv_stock_count_lines.variance_adjustment_id')
            ->where('inv_stock_count_lines.tenant_id', $this->context->tenantId())
            ->where('inv_stock_count_lines.stock_count_id', $stockCountId)
            ->orderBy('inv_items.code')
            ->orderBy('inv_batches.batch_number')
            ->get([
                'inv_stock_count_lines.id as line_id',
                'inv_stock_count_lines.item_id',
                'inv_items.code as item_code',
                'inv_items.name as item_name',
                'inv_items.uom as uom',
                'inv_items.pack_unit as pack_unit',
                'inv_items.pack_size as pack_size',
                'inv_items.is_controlled as is_controlled',
                'inv_stock_count_lines.batch_id',
                'inv_batches.batch_number',
                'inv_stock_count_lines.system_qty',
                'inv_stock_count_lines.counted_qty',
                'inv_stock_count_lines.variance_adjustment_id',
                'inv_stock_adjustments.status as adjustment_status',
                'inv_stock_count_lines.variance_skipped_reason',
            ]);

        return array_values($rows->map(fn (object $row): StockCountLineSummary => new StockCountLineSummary(
            lineId: (string) $row->line_id,
            itemId: (string) $row->item_id,
            itemCode: (string) $row->item_code,
            itemName: (string) $row->item_name,
            uom: (string) $row->uom,
            packUnit: $row->pack_unit !== null ? (string) $row->pack_unit : null,
            packSize: $row->pack_size !== null ? Quantity::of((string) $row->pack_size) : null,
            batchId: $row->batch_id !== null ? (string) $row->batch_id : null,
            batchNumber: $row->batch_number !== null ? (string) $row->batch_number : null,
            systemQty: Quantity::of((string) $row->system_qty),
            countedQty: $row->counted_qty !== null ? Quantity::of((string) $row->counted_qty) : null,
            variance: $row->counted_qty !== null
                ? Quantity::sub((string) $row->counted_qty, (string) $row->system_qty)
                : null,
            isControlled: (bool) $row->is_controlled,
            adjustmentId: $row->variance_adjustment_id !== null ? (string) $row->variance_adjustment_id : null,
            adjustmentStatus: $row->adjustment_status !== null ? (string) $row->adjustment_status : null,
            varianceSkippedReason: $row->variance_skipped_reason !== null ? (string) $row->variance_skipped_reason : null,
        ))->all());
    }

    /**
     * @param  list<string>  $countIds
     * @return array<string, array{lines: int, counted: int, variance: int}>
     */
    private function lineStats(array $countIds): array
    {
        if ($countIds === []) {
            return [];
        }

        $rows = DB::table('inv_stock_count_lines')
            ->where('inv_stock_count_lines.tenant_id', $this->context->tenantId())
            ->whereIn('inv_stock_count_lines.stock_count_id', $countIds)
            ->groupBy('inv_stock_count_lines.stock_count_id')
            ->get([
                'inv_stock_count_lines.stock_count_id',
                DB::raw('count(*) as line_count'),
                DB::raw('count(inv_stock_count_lines.counted_qty) as counted_count'),
                DB::raw('count(case when inv_stock_count_lines.counted_qty is not null and inv_stock_count_lines.counted_qty <> inv_stock_count_lines.system_qty then 1 end) as variance_count'),
            ]);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->stock_count_id] = [
                'lines' => (int) $row->line_count,
                'counted' => (int) $row->counted_count,
                'variance' => (int) $row->variance_count,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function headerColumns(): array
    {
        return [
            'inv_stock_counts.id as count_id',
            'inv_stock_counts.count_number',
            'inv_stock_counts.facility_id',
            'inv_stock_counts.location_id',
            'inv_stock_locations.code as location_code',
            'inv_stock_locations.name as location_name',
            'inv_stock_counts.status',
            'inv_stock_counts.notes',
            'inv_stock_counts.started_by_user_id',
            'inv_stock_counts.started_at',
            'inv_stock_counts.closed_at',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{lines: int, counted: int, variance: int}  $stat
     */
    private function toSummary(array $row, array $stat): StockCountSummary
    {
        return new StockCountSummary(
            countId: (string) $row['count_id'],
            countNumber: (string) $row['count_number'],
            facilityId: (string) $row['facility_id'],
            locationId: (string) $row['location_id'],
            locationCode: (string) $row['location_code'],
            locationName: (string) $row['location_name'],
            status: (string) $row['status'],
            notes: $row['notes'] !== null ? (string) $row['notes'] : null,
            startedByUserId: (string) $row['started_by_user_id'],
            startedAt: CarbonImmutable::parse((string) $row['started_at'])->toIso8601String(),
            closedAt: $row['closed_at'] !== null ? CarbonImmutable::parse((string) $row['closed_at'])->toIso8601String() : null,
            lineCount: $stat['lines'],
            countedCount: $stat['counted'],
            varianceCount: $stat['variance'],
        );
    }
}
