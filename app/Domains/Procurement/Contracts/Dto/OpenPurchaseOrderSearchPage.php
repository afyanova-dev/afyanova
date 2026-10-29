<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * A page of open purchase orders for a receiving search (P0-03).
 *
 * `OpenPurchaseOrderRef::lines` is intentionally empty here: the picker needs
 * only the PO identity and line counts. The selected PO's lines are fetched by
 * `OpenPurchaseOrderLinePage`, so a 500-line order never travels with every
 * search result.
 */
final readonly class OpenPurchaseOrderSearchPage
{
    /**
     * @param  list<OpenPurchaseOrderRef>  $orders
     */
    public function __construct(
        public array $orders,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {}
}
