<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application;

use App\Domains\Procurement\Application\Query\GetGoodsReceiptPurchaseOrder;
use App\Domains\Procurement\Application\Query\GetItemPriceReferences;
use App\Domains\Procurement\Application\Query\GetOpenPurchaseOrders;
use App\Domains\Procurement\Application\Query\SearchReceivablePurchaseOrderLines;
use App\Domains\Procurement\Application\Query\SearchReceivablePurchaseOrders;
use App\Domains\Procurement\Contracts\Dto\GoodsReceiptPurchaseOrderRef;
use App\Domains\Procurement\Contracts\Dto\ItemPriceReference;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderLinePage;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderLineRef;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderRef;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderSearchPage;
use App\Domains\Procurement\Contracts\Dto\PoLineSummary;
use App\Domains\Procurement\Contracts\Dto\PurchaseOrderSummary;
use App\Domains\Procurement\Contracts\ProcurementApi;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Quantity;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * The `ProcurementApi` implementation (frontend-foundation-inventory-operations.md
 * Phase 7).
 *
 * Pure delegation: `getOpenPurchaseOrders` crosses the QueryBus via the existing
 * `#[ProtectedQuery]` `GetOpenPurchaseOrders` (so the caller's own
 * `procurement.access` authorization runs) and reduces each PO to its OPEN STOCK
 * lines as a NARROW cross-domain reference — the `InventoryApiService` precedent.
 * No new query, reader, policy or migration.
 */
final class ProcurementApiService implements ProcurementApi
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly TenantContext $context,
    ) {}

    public function getOpenPurchaseOrders(string $facilityId): array
    {
        /** @var list<PurchaseOrderSummary> $summaries */
        $summaries = $this->queryBus->dispatch(new GetOpenPurchaseOrders($facilityId));

        $refs = [];

        foreach ($summaries as $summary) {
            $lines = [];

            foreach ($summary->lines as $line) {
                /** @var PoLineSummary $line */
                if ($line->lineType !== PoLine::LINE_TYPE_STOCK
                    || $line->status !== PoLine::STATUS_OPEN
                    || $line->itemRef === null) {
                    continue; // SERVICE lines and satisfied/cancelled lines never receive stock
                }

                $remainingQty = Quantity::subtract($line->orderedBaseQty, $line->receivedQty);
                if (! Quantity::isPositive($remainingQty)) {
                    continue;
                }

                $lines[] = new OpenPurchaseOrderLineRef(
                    lineId: $line->lineId,
                    lineNo: $line->lineNo,
                    itemRef: $line->itemRef,
                    itemCode: $line->itemCode,
                    itemName: $line->itemName,
                    orderedQty: $line->orderedBaseQty,
                    receivedQty: $line->receivedQty,
                    remainingQty: $remainingQty,
                    uom: $line->uom,
                    enteredUnit: $line->packSize !== null ? $line->unit : null,
                    enteredQty: $line->packSize !== null ? $line->orderedQty : null,
                    packSize: $line->packSize,
                );
            }

            if ($lines === []) {
                continue; // nothing receivable on this PO — never a selection candidate
            }

            $refs[] = new OpenPurchaseOrderRef(
                purchaseOrderId: $summary->purchaseOrderId,
                poNumber: $summary->poNumber,
                facilityId: $summary->facilityId,
                status: $summary->status,
                lines: $lines,
                openLineCount: count($lines),
                remainingLineCount: count($lines),
            );
        }

        return $refs;
    }

    public function goodsReceiptPurchaseOrder(string $facilityId, string $poNumber): ?GoodsReceiptPurchaseOrderRef
    {
        /** @var GoodsReceiptPurchaseOrderRef|null $result */
        $result = $this->queryBus->dispatch(new GetGoodsReceiptPurchaseOrder($facilityId, $poNumber));

        return $result;
    }

    public function searchOpenPurchaseOrders(
        string $facilityId,
        string $query = '',
        int $page = 1,
        int $perPage = 20,
    ): OpenPurchaseOrderSearchPage {
        /** @var OpenPurchaseOrderSearchPage $result */
        $result = $this->queryBus->dispatch(new SearchReceivablePurchaseOrders(
            facilityId: $facilityId,
            query: $query,
            page: $page,
            perPage: $perPage,
        ));

        return $result;
    }

    public function searchOpenPurchaseOrderLines(
        string $facilityId,
        string $purchaseOrderId,
        string $query = '',
        int $page = 1,
        int $perPage = 100,
    ): OpenPurchaseOrderLinePage {
        /** @var OpenPurchaseOrderLinePage $result */
        $result = $this->queryBus->dispatch(new SearchReceivablePurchaseOrderLines(
            facilityId: $facilityId,
            purchaseOrderId: $purchaseOrderId,
            query: $query,
            page: $page,
            perPage: $perPage,
        ));

        return $result;
    }

    public function getItemPriceReferences(string $supplierId, string $itemId): ItemPriceReference
    {
        $facilityId = $this->context->facilityId();

        if ($facilityId === null || $facilityId === '') {
            throw new InvalidArgumentException('Select an active facility context before looking up prices.');
        }

        /** @var ItemPriceReference $result */
        $result = $this->queryBus->dispatch(new GetItemPriceReferences(
            facilityId: $facilityId,
            supplierId: $supplierId,
            itemId: $itemId,
        ));

        return $result;
    }
}
