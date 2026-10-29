<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts;

use App\Domains\Procurement\Contracts\Dto\GoodsReceiptPurchaseOrderRef;
use App\Domains\Procurement\Contracts\Dto\ItemPriceReference;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderLinePage;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderRef;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderSearchPage;

/**
 * The Procurement surface other modules may import (IC §2.1). Grows per
 * consumer — do not add a method without a concrete caller (IC §26).
 *
 * Unlike `InventoryApi`, Procurement publishes no writes: the PO receipt is a
 * SYSTEM projection driven by Inventory's `StockReceived` event
 * (`OnStockReceived` → `RecordPurchaseOrderReceipt`), never an inbound port.
 * The consumer here is Inventory's goods-receipt workflow
 * (frontend-foundation-inventory-operations.md Phase 7), which needs a
 * validated, non-free-text purchase-order selection so a receipt can be
 * reconciled without trusting a caller-supplied PO identifier.
 */
interface ProcurementApi
{
    /**
     * Find a purchase order at one facility and return only the values needed
     * to validate a receipt and snapshot its ordered unit and price.
     */
    public function goodsReceiptPurchaseOrder(string $facilityId, string $poNumber): ?GoodsReceiptPurchaseOrderRef;

    /**
     * The open (ISSUED | PARTIALLY_RECEIVED) purchase orders at a facility,
     * reduced to their OPEN STOCK lines — the receivable set for PO-aware goods
     * receipt. PROTECTED — delegates to the `GetOpenPurchaseOrders`
     * `#[ProtectedQuery]`, so the caller must hold `procurement.access` at the
     * facility (RLS + the handler's facility refinement). Throws
     * `App\Shared\Domain\NotFound` when out of scope.
     *
     * @return list<OpenPurchaseOrderRef>
     */
    public function getOpenPurchaseOrders(string $facilityId): array;

    /**
     * P0-03 — server-side search for open POs. Results carry line counts but no
     * lines; the selected PO's lines are loaded through `searchOpenPurchaseOrderLines`.
     * PROTECTED by the same `view` policy as the full open-PO projection.
     */
    public function searchOpenPurchaseOrders(
        string $facilityId,
        string $query = '',
        int $page = 1,
        int $perPage = 20,
    ): OpenPurchaseOrderSearchPage;

    /**
     * P0-03 — one page of open STOCK lines for the selected PO, searchable by
     * line number / item code / item name. PROTECTED by the same `view` policy.
     */
    public function searchOpenPurchaseOrderLines(
        string $facilityId,
        string $purchaseOrderId,
        string $query = '',
        int $page = 1,
        int $perPage = 100,
    ): OpenPurchaseOrderLinePage;

    /**
     * P2-02 — last supplier price, last any-supplier price, and the usable
     * active-contract price for an item at the ACTIVE facility. PROTECTED by
     * the order `view` policy; department-scoped users only see prices from
     * their own departments' requests.
     */
    public function getItemPriceReferences(string $supplierId, string $itemId): ItemPriceReference;
}
