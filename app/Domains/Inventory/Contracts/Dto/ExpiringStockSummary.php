<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * One batch of stock that is expired, or will expire inside the near-expiry
 * window (frontend-foundation-inventory-operations.md Phase 0 disposition
 * policy). Read-only: removing it is a normal two-person `DECREASE` adjustment,
 * never a balance edit. NOT an event payload.
 *
 * `daysRemaining` is display only: 0 means the batch expires today and is still
 * usable (a batch is usable through the END of its expiry date), a negative
 * number means it is already expired. `expired` comes from the same
 * `Batch::isExpired()` rule the dispense gate uses, so a future batch status
 * (quarantine / recall) can add ineligibility without being mislabelled as
 * expiry.
 */
final readonly class ExpiringStockSummary
{
    public function __construct(
        public string $balanceId,
        public string $itemId,
        public string $itemCode,
        public string $itemName,
        public string $locationId,
        public string $locationCode,
        public string $batchId,
        public string $batchNumber,
        public string $expiryDate,
        public int $daysRemaining,
        public string $onHandQty,
        public string $uom,
        public bool $expired,
        public bool $controlled,
    ) {}
}
