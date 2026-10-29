<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * One page of the goods-receipt register (Phase 1 / P1-08).
 */
final readonly class GoodsReceiptSearchPage
{
    /**
     * @param  list<GoodsReceiptSummary>  $receipts
     */
    public function __construct(
        public array $receipts,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
        public string $search,
    ) {}
}
