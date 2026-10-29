<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * One stock reservation (18-inventory-and-warehousing-domain.md §112–§116,
 * §150, §257; ADR-180 D-G). NOT an event payload — `reason` IS returned to an
 * authorised reader.
 */
final readonly class StockReservationSummary
{
    public function __construct(
        public string $reservationId,
        public string $facilityId,
        public string $itemId,
        public string $locationId,
        public ?string $batchId,
        public string $reservedQty,
        public string $consumerType,
        public ?string $consumerRef,
        public ?string $reason,
        public ?string $holdUntil,
        public string $status,
        public ?string $releasedReason,
        public ?string $fulfilledByMovementId,
        public string $requestedByUserId,
        public ?string $releasedByUserId,
        public ?string $createdAt,
    ) {}
}
