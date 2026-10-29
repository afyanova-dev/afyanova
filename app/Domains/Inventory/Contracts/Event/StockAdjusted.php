<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Event;

/**
 * An approved stock adjustment was posted, changing the recorded balance
 * (18-inventory-and-warehousing-domain.md §94, §323; ADR-170 D-N).
 *
 * PHI-safe: identifiers, coded values, quantities and timestamps only (the
 * ADR-136 deny-list — `reason_code` is CODED, `reason_text` is never carried).
 * Consumers (their own phases): Reporting, Costing.
 */
final readonly class StockAdjusted
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $itemId,
        public string $locationId,
        public ?string $batchId,
        public string $movementId,
        public string $movementNumber,
        public string $adjustmentId,
        public string $direction,
        public string $quantity,
        public string $uom,
        public string $reasonCode,
        public string $approvedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
