<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Event;

/**
 * Stock changed location without changing the facility total (Phase 8).
 *
 * Emitted once per side: an OUT at the source balance and an IN at the
 * destination balance, both carrying the same `transferId`. PHI-safe:
 * identifiers, quantities and timestamps only (the ADR-136 deny-list).
 */
final readonly class StockTransferred
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $itemId,
        public string $locationId,
        public string $counterpartLocationId,
        public ?string $batchId,
        public string $transferId,
        public string $movementId,
        public string $movementNumber,
        public string $direction,
        public string $quantity,
        public string $uom,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
