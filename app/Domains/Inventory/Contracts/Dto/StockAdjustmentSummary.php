<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * One stock-adjustment workflow record (18-inventory-and-warehousing-domain.md
 * §94, §95, §250; ADR-170 D-M). NOT an event payload.
 */
final readonly class StockAdjustmentSummary
{
    public function __construct(
        public string $adjustmentId,
        public string $facilityId,
        public string $itemId,
        public string $locationId,
        public ?string $batchId,
        public string $direction,
        public string $quantity,
        public string $reasonCode,
        public ?string $reasonText,
        public ?string $witnessName,
        public string $status,
        public string $requestedByUserId,
        public string $requestedAt,
        public ?string $approvedByUserId,
        public ?string $approvedAt,
        public ?string $rejectionReason,
        public ?string $resultingMovementId,
    ) {}
}
