<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Reader;

use App\Domains\Inventory\Contracts\Dto\StockTransferSummary;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\StockTransferReader;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class EloquentStockTransferReader implements StockTransferReader
{
    public function __construct(private readonly TenantContext $context) {}

    public function recentForFacility(string $facilityId, int $limit = 25): array
    {
        $limit = max(1, min($limit, 100));

        // One joined read for the screen. The tenant filter is explicit here
        // because the query does not start from the model; RLS still enforces
        // it at the database.
        $rows = DB::table('inv_stock_transfers')
            ->join('inv_items', 'inv_items.id', '=', 'inv_stock_transfers.item_id')
            ->join('inv_stock_locations as source_location', 'source_location.id', '=', 'inv_stock_transfers.from_location_id')
            ->join('inv_stock_locations as destination_location', 'destination_location.id', '=', 'inv_stock_transfers.to_location_id')
            ->leftJoin('inv_batches', 'inv_batches.id', '=', 'inv_stock_transfers.batch_id')
            ->where('inv_stock_transfers.tenant_id', $this->context->tenantId())
            ->where('inv_stock_transfers.facility_id', $facilityId)
            ->select([
                'inv_stock_transfers.id as transfer_id',
                'inv_stock_transfers.transfer_number',
                'inv_stock_transfers.item_id',
                'inv_items.code as item_code',
                'inv_items.name as item_name',
                'inv_items.uom as uom',
                'inv_stock_transfers.from_location_id',
                'source_location.code as from_location_code',
                'inv_stock_transfers.to_location_id',
                'destination_location.code as to_location_code',
                'inv_stock_transfers.batch_id',
                'inv_batches.batch_number',
                'inv_stock_transfers.quantity',
                'inv_stock_transfers.reason_text',
                'inv_stock_transfers.moved_by_user_id',
                'inv_stock_transfers.moved_at',
            ])
            ->orderByDesc('inv_stock_transfers.moved_at')
            ->orderByDesc('inv_stock_transfers.transfer_number')
            ->limit($limit)
            ->get();

        return array_values($rows->map(fn (object $row): StockTransferSummary => new StockTransferSummary(
            transferId: (string) $row->transfer_id,
            transferNumber: (string) $row->transfer_number,
            itemId: (string) $row->item_id,
            itemCode: (string) $row->item_code,
            itemName: (string) $row->item_name,
            fromLocationId: (string) $row->from_location_id,
            fromLocationCode: (string) $row->from_location_code,
            toLocationId: (string) $row->to_location_id,
            toLocationCode: (string) $row->to_location_code,
            batchId: $row->batch_id !== null ? (string) $row->batch_id : null,
            batchNumber: $row->batch_number !== null ? (string) $row->batch_number : null,
            quantity: Quantity::of((string) $row->quantity),
            uom: (string) $row->uom,
            reasonText: $row->reason_text !== null ? (string) $row->reason_text : null,
            movedByUserId: (string) $row->moved_by_user_id,
            movedAt: CarbonImmutable::parse((string) $row->moved_at)->toIso8601String(),
        ))->all());
    }
}
