<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * One stock-movement ledger entry with its running balance
 * (18-inventory-and-warehousing-domain.md §84, §213, §214; ADR-170 D-M).
 * NOT an event payload — `reasonText` is row-only and returned only to an
 * authorised reader through `#[ProtectedQuery]`.
 */
final readonly class StockLedgerEntrySummary
{
    public function __construct(
        public string $movementId,
        public string $movementNumber,
        public string $movementType,
        public string $direction,
        public string $quantity,
        public string $uom,
        public ?string $enteredUnit,
        public ?string $enteredQuantity,
        public ?string $packSize,
        public string $runningBalance,
        public string $sourceType,
        public ?string $sourceRef,
        public ?string $poRef,
        public ?string $batchId,
        public ?string $reasonText,
        public string $actorUserId,
        public string $occurredAt,
    ) {}
}
