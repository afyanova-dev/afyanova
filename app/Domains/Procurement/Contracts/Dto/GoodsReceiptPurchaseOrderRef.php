<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * The minimum PO data Inventory needs to validate and snapshot a goods receipt.
 *
 * @param  list<GoodsReceiptPurchaseOrderLineRef>  $lines
 */
final readonly class GoodsReceiptPurchaseOrderRef
{
    /**
     * @param  list<GoodsReceiptPurchaseOrderLineRef>  $lines
     */
    public function __construct(
        public string $purchaseOrderId,
        public string $facilityId,
        public string $poNumber,
        public string $currency,
        public string $status,
        public array $lines,
    ) {}
}
