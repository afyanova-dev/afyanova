<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Event;

/**
 * A bed occupancy was ended / bed released (24-bed-and-ward-management-domain.md §11; ADR-148 D-J).
 *
 * PHI-safe: identifiers, timestamps, and coded end reason only.
 */
final readonly class BedReleased
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $bedId,
        public string $encounterId,
        public string $occupancyId,
        public string $endedAt,
        public string $endReason,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
