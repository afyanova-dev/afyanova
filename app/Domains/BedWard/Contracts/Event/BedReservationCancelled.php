<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Event;

/**
 * A bed reservation was cancelled (24-bed-and-ward-management-domain.md §9;
 * ADR-149 D-B/D-H). PHI-safe: identifiers only.
 */
final readonly class BedReservationCancelled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $reservationId,
        public string $bedId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
