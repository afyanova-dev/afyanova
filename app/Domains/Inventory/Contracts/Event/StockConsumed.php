<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Event;

/**
 * Stock was consumed for an operational / clinical activity
 * (18-inventory-and-warehousing-domain.md §70, §239; ADR-170 D-N).
 *
 * PHI-safe: identifiers, coded values, quantities and timestamps only (the
 * ADR-136 deny-list). `sourceRef` is the OPAQUE id of the causing activity (a
 * procedure / case / dispensing id) — an identifier, ADR-136-allowed; never
 * patient demographics. Consumers (their own phases): Billing / Costing (§239),
 * Clinical, Reporting.
 */
final readonly class StockConsumed
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
        public ?string $sourceRef,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
