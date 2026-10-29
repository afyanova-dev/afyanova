<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * Price evidence for one supplier/item at the active facility (P2-02): the last
 * price this supplier charged, the last price any supplier charged, and any
 * usable active contract price. All values are reporting aids — the buyer still
 * enters the agreed price and the server validates it.
 */
final readonly class ItemPriceReference
{
    public function __construct(
        public string $itemId,
        public string $supplierId,
        public string $currency,
        public ?ItemPricePoint $lastSupplierPrice,
        public ?ItemPricePoint $lastAnySupplierPrice,
        public ?ContractPriceReference $contractPrice,
    ) {}
}
