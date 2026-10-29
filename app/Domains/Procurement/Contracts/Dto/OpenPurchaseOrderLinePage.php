<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * A page of open STOCK lines for one purchase order (P0-03).
 *
 * Only lines with a positive remaining quantity are returned. The receiving
 * surface searches and pages these instead of loading a 500-line order whole.
 */
final readonly class OpenPurchaseOrderLinePage
{
    /**
     * @param  list<OpenPurchaseOrderLineRef>  $lines
     */
    public function __construct(
        public array $lines,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {}
}
