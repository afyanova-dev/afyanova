<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One historical purchase-order price for an item (P2-02). `unitPrice` is the
 * price of one entered `unit`; `baseUnitPrice` normalises it to one stock/base
 * unit so the buying form can suggest a price for either unit.
 */
final readonly class ItemPricePoint
{
    public function __construct(
        public string $supplierId,
        public string $poNumber,
        public string $unitPrice,
        public string $baseUnitPrice,
        public string $unit,
        public string $uom,
        public ?string $packSize,
        public string $orderedAt,
    ) {}
}
