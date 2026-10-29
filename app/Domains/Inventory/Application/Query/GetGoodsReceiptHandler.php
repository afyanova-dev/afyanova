<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\GoodsReceiptDetail;
use App\Domains\Inventory\Contracts\Dto\GoodsReceiptLineSummary;
use App\Domains\Inventory\Domain\Model\GoodsReceipt;
use App\Domains\Inventory\Domain\Model\GoodsReceiptLine;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Shared\Domain\NotFound;
use Carbon\CarbonImmutable;

/**
 * Read one goods receipt + lines for the detail panel and printed GRN
 * (Phase 1 / P1-07–P1-08).
 */
final class GetGoodsReceiptHandler
{
    public function __invoke(GetGoodsReceipt $query): GoodsReceiptDetail
    {
        $receipt = GoodsReceipt::query()->whereKey($query->goodsReceiptId)->first()
            ?? throw NotFound::aggregate(GoodsReceipt::class, $query->goodsReceiptId);

        $lines = GoodsReceiptLine::query()
            ->where('receipt_id', $receipt->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $items = Item::query()
            ->whereIn('id', $lines->pluck('item_id')->unique())
            ->get()
            ->keyBy('id');

        $movements = StockMovement::query()
            ->whereIn('id', $lines->pluck('movement_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $location = StockLocation::query()->whereKey($receipt->location_id)->first();

        return new GoodsReceiptDetail(
            goodsReceiptId: (string) $receipt->id,
            facilityId: (string) $receipt->facility_id,
            receiptNumber: (string) $receipt->receipt_number,
            receivedAt: CarbonImmutable::parse((string) $receipt->received_at)->toIso8601String(),
            recordedAt: CarbonImmutable::parse((string) $receipt->recorded_at)->toIso8601String(),
            sourceType: (string) $receipt->source_type,
            poRef: $receipt->po_ref !== null ? (string) $receipt->po_ref : null,
            supplierRef: $receipt->supplier_ref !== null ? (string) $receipt->supplier_ref : null,
            locationId: (string) $receipt->location_id,
            locationCode: $location !== null ? (string) $location->code : '',
            locationName: $location !== null ? (string) $location->name : '',
            deliveryNoteNumber: $receipt->delivery_note_number !== null ? (string) $receipt->delivery_note_number : null,
            deliveryNoteMissingReason: $receipt->delivery_note_missing_reason !== null ? (string) $receipt->delivery_note_missing_reason : null,
            invoiceNumber: $receipt->invoice_number !== null ? (string) $receipt->invoice_number : null,
            invoiceDate: $receipt->invoice_date !== null
                ? CarbonImmutable::parse((string) $receipt->invoice_date)->toDateString()
                : null,
            invoiceStatus: (string) $receipt->invoice_status,
            invoiceCurrency: (string) $receipt->invoice_currency,
            invoiceTotal: $receipt->invoice_total !== null ? (string) $receipt->invoice_total : null,
            status: (string) $receipt->status,
            matchStatus: (string) $receipt->match_status,
            remarks: $receipt->remarks !== null ? (string) $receipt->remarks : null,
            receivedByUserId: (string) $receipt->received_by_user_id,
            lines: array_values($lines->map(function (GoodsReceiptLine $line) use ($items, $movements): GoodsReceiptLineSummary {
                $item = $items->get((string) $line->item_id);
                $movement = $line->movement_id !== null ? $movements->get((string) $line->movement_id) : null;

                return new GoodsReceiptLineSummary(
                    receiptLineId: (string) $line->id,
                    itemId: (string) $line->item_id,
                    itemCode: $item !== null ? (string) $item->code : '',
                    itemName: $item !== null ? (string) $item->name : '',
                    poLineRef: $line->po_line_ref !== null ? (string) $line->po_line_ref : null,
                    enteredUnit: (string) $line->entered_unit,
                    enteredQuantity: (string) $line->entered_quantity,
                    packSize: (string) $line->pack_size,
                    quantityDelivered: (string) $line->quantity_delivered,
                    quantityRejected: (string) $line->quantity_rejected,
                    quantityAccepted: (string) $line->quantity_accepted,
                    poUnit: $line->po_unit !== null ? (string) $line->po_unit : null,
                    poUnitPrice: $line->po_unit_price !== null ? (string) $line->po_unit_price : null,
                    invoiceQuantity: $line->invoice_quantity !== null ? (string) $line->invoice_quantity : null,
                    invoiceUnitPrice: $line->invoice_unit_price !== null ? (string) $line->invoice_unit_price : null,
                    uom: (string) $line->uom,
                    batchNumber: $line->batch_number !== null ? (string) $line->batch_number : null,
                    expiryDate: $line->expiry_date !== null
                        ? CarbonImmutable::parse((string) $line->expiry_date)->toDateString()
                        : null,
                    manufactureDate: $line->manufacture_date !== null
                        ? CarbonImmutable::parse((string) $line->manufacture_date)->toDateString()
                        : null,
                    condition: (string) $line->condition,
                    substitutedForItemId: $line->substituted_for_item_id !== null
                        ? (string) $line->substituted_for_item_id
                        : null,
                    conditionNotes: $line->condition_notes !== null ? (string) $line->condition_notes : null,
                    rejectionReason: $line->rejection_reason !== null ? (string) $line->rejection_reason : null,
                    movementNumber: $movement !== null ? (string) $movement->movement_number : null,
                );
            })->all()),
        );
    }
}
