<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Event;

/**
 * A bed reservation was fulfilled by AssignBed (24-bed-and-ward-management-domain.md §8.3;
 * ADR-149 D-B/D-E/D-H). PHI-safe: identifiers only.
 */
final readonly class BedReservationFulfilled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $reservationId,
        public string $bedId,
        public string $occupancyId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
