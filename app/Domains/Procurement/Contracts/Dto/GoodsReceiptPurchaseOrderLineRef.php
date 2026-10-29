<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

final readonly class GoodsReceiptPurchaseOrderLineRef
{
    public function __construct(
        public string $lineId,
        public string $lineType,
        public ?string $itemRef,
        public string $unit,
        public string $unitPrice,
        public string $status,
    ) {}
}
