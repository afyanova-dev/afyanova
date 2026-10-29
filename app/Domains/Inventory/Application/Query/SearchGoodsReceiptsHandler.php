<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\GoodsReceiptSearchPage;
use App\Domains\Inventory\Contracts\Dto\GoodsReceiptSummary;
use App\Domains\Inventory\Domain\Model\GoodsReceipt;
use App\Domains\Inventory\Domain\Model\GoodsReceiptLine;
use App\Domains\Inventory\Domain\Model\StockLocation;
use Carbon\CarbonImmutable;

/**
 * Goods-receipt register search (Phase 1 / P1-08).
 *
 * Searches the GRN number, PO reference, delivery-note number and supplier
 * invoice number. The facility is always the active facility; RLS is the
 * tenant backstop. Line counts come from one grouped query, never one query per
 * receipt.
 */
final class SearchGoodsReceiptsHandler
{
    public function __invoke(SearchGoodsReceipts $query): GoodsReceiptSearchPage
    {
        $page = max(1, $query->page);
        $perPage = min(100, max(1, $query->perPage));
        $search = trim($query->query);

        $paginator = GoodsReceipt::query()
            ->where('facility_id', $query->facilityId)
            ->when($search !== '', function ($builder) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';

                $builder->where(function ($inner) use ($like): void {
                    $inner->where('receipt_number', 'ilike', $like)
                        ->orWhere('po_ref', 'ilike', $like)
                        ->orWhere('delivery_note_number', 'ilike', $like)
                        ->orWhere('invoice_number', 'ilike', $like)
                        ->orWhere('supplier_ref', 'ilike', $like);
                });
            })
            ->orderByDesc('received_at')
            ->orderByDesc('receipt_number')
            ->paginate($perPage, ['*'], 'page', $page);

        $receipts = collect($paginator->items());

        $lineStats = $receipts->isEmpty()
            ? collect()
            : GoodsReceiptLine::query()
                ->whereIn('receipt_id', $receipts->pluck('id'))
                ->selectRaw('receipt_id, count(*) as line_count, count(*) filter (where quantity_rejected > 0) as rejected_line_count')
                ->groupBy('receipt_id')
                ->get()
                ->keyBy('receipt_id');

        $locations = StockLocation::query()
            ->whereIn('id', $receipts->pluck('location_id')->unique())
            ->get()
            ->keyBy('id');

        return new GoodsReceiptSearchPage(
            receipts: array_values($receipts->map(function (GoodsReceipt $receipt) use ($lineStats, $locations): GoodsReceiptSummary {
                $stats = $lineStats->get((string) $receipt->id);
                $location = $locations->get((string) $receipt->location_id);

                return new GoodsReceiptSummary(
                    goodsReceiptId: (string) $receipt->id,
                    facilityId: (string) $receipt->facility_id,
                    receiptNumber: (string) $receipt->receipt_number,
                    receivedAt: CarbonImmutable::parse((string) $receipt->received_at)->toIso8601String(),
                    sourceType: (string) $receipt->source_type,
                    poRef: $receipt->po_ref !== null ? (string) $receipt->po_ref : null,
                    supplierRef: $receipt->supplier_ref !== null ? (string) $receipt->supplier_ref : null,
                    locationId: (string) $receipt->location_id,
                    locationCode: $location !== null ? (string) $location->code : '',
                    locationName: $location !== null ? (string) $location->name : '',
                    deliveryNoteNumber: $receipt->delivery_note_number !== null ? (string) $receipt->delivery_note_number : null,
                    invoiceNumber: $receipt->invoice_number !== null ? (string) $receipt->invoice_number : null,
                    invoiceStatus: (string) $receipt->invoice_status,
                    invoiceTotal: $receipt->invoice_total !== null ? (string) $receipt->invoice_total : null,
                    lineCount: $stats !== null ? (int) $stats->line_count : 0,
                    rejectedLineCount: $stats !== null ? (int) $stats->rejected_line_count : 0,
                );
            })->all()),
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
            search: $search,
        );
    }
}
