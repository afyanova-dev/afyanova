<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Event;

/**
 * Stock was reserved for an intended consumer
 * (18-inventory-and-warehousing-domain.md §112, §236; ADR-170 D-N / ADR-180 D-G).
 *
 * PHI-safe: identifiers, coded values, quantities and timestamps only (the
 * ADR-136 deny-list — `reason` never carried). `consumerType` / `consumerRef`
 * are opaque business values, not PHI (§282). Consumers (their own phases):
 * Pharmacy / Theatre reservation coordination, Reporting. No Inv.2 consumer.
 */
final readonly class StockReserved
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $reservationId,
        public string $itemId,
        public string $locationId,
        public ?string $batchId,
        public string $quantity,
        public string $consumerType,
        public ?string $consumerRef,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
