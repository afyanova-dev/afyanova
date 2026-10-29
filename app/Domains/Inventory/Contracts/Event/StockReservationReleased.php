<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Event;

/**
 * A stock reservation left ACTIVE — released, expired, cancelled, or fulfilled
 * by a consumption (18-inventory-and-warehousing-domain.md §113, §114, §116,
 * §236; ADR-170 D-N / ADR-180 D-G).
 *
 * PHI-safe: identifiers, coded values, quantities and timestamps only.
 * `reason` is the CODED transition (RELEASED | EXPIRED | CANCELLED | FULFILLED),
 * NOT the free-text `reason` column. Consumers (their own phases):
 * Pharmacy / Theatre ("your reservation lapsed"), Reporting. No Inv.2 consumer.
 */
final readonly class StockReservationReleased
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
        public string $reason,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
