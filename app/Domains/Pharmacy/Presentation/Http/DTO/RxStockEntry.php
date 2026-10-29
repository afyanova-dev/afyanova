<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One per-location, per-batch stock line for the SELECTED dispense item
 * (`GetAvailableStock` → `AvailableStockSummary::byLocation`
 * (18-inventory-and-warehousing-domain.md §29, §30, §327)). Stock facts belong
 * to Inventory; Pharmacy only presents them.
 *
 * `eligible` is the Phase 4 availability flag — an expired batch is not
 * dispensable and is excluded from the location totals the panel shows. This is
 * a display contract only: the reserve / consume handlers re-check expiry
 * server-side. A location's availability is never inferred from the facility
 * sum.
 */
#[TypeScript]
final readonly class RxStockEntry
{
    public function __construct(
        public string $locationId,
        public ?string $batchId,
        public ?string $batchNumber,
        public ?string $expiryDate,
        public string $onHand,
        public string $reserved,
        public string $available,
        public string $uom,
        public bool $eligible,
    ) {}
}
