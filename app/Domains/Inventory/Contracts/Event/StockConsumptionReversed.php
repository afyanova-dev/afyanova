<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Event;

/**
 * A prior stock consumption was reversed — the compensating IN movement put the
 * quantity back on hand (18-inventory-and-warehousing-domain.md §84, §239;
 * ADR-185 D-C). The inverse fact of {@see StockConsumed}.
 *
 * PHI-safe (the ADR-136 deny-list): identifiers, coded values, quantities and
 * timestamps only. `originalMovementId` is the reversed CONSUMPTION OUT;
 * `sourceRef` (opaque) is the causing activity — a pharmacy dispensing-reversal
 * id — never patient demographics. NO Pharm.3b consumer.
 */
final readonly class StockConsumptionReversed
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
        public string $originalMovementId,
        public string $quantity,
        public string $uom,
        public ?string $sourceRef,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
