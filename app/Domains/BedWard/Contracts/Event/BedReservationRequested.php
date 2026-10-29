<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Event;

/**
 * A bed reservation was requested (24-bed-and-ward-management-domain.md §11; ADR-149 D-H).
 *
 * PHI-safe: identifiers and coded values only — no free-text `reason`.
 */
final readonly class BedReservationRequested
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $reservationId,
        public string $bedId,
        public ?string $encounterId,
        public string $reason,
        public string $holdUntil,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
