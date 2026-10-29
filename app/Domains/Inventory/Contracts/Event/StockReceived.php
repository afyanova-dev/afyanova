<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Event;

/**
 * Stock was physically received into a location
 * (18-inventory-and-warehousing-domain.md §52, §237; ADR-170 D-N).
 *
 * PHI-safe: identifiers, coded values, quantities and timestamps only (the
 * ADR-136 deny-list — `reason_text` never carried). `poRef` / `sourceType` are
 * Procurement-facing coded/opaque values, not PHI. Consumers (their own
 * phases): Procurement (PO fulfilment — §125 / §126), Reporting, Costing.
 */
final readonly class StockReceived
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
        public string $quantity,
        public string $uom,
        public string $sourceType,
        public ?string $poRef,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
        public ?string $poLineRef = null,
        public ?string $goodsReceiptRef = null,
    ) {}
}
