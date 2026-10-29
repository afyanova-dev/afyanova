<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Service;

use App\Domains\Inventory\Domain\Quantity;

/**
 * The outcome of reconciling a materialised balance against its movement ledger
 * (18-inventory-and-warehousing-domain.md §86, §253, §408.4; ADR-170 D-J).
 * `drift` must be "0.0000".
 */
final readonly class ReconciliationResult
{
    public function __construct(
        public string $itemId,
        public string $locationId,
        public ?string $batchId,
        public string $balanceOnHand,
        public string $ledgerSum,
        public string $drift,
    ) {}

    public function isBalanced(): bool
    {
        return Quantity::compare($this->drift, '0') === 0;
    }
}
