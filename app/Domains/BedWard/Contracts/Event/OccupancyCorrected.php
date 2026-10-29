<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Event;

/**
 * A bed occupancy was corrected (24-bed-and-ward-management-domain.md §11; ADR-148 D-J).
 *
 * PHI-safe: identifiers and changed field names only — no previous/new values,
 * and no reason text.
 */
final readonly class OccupancyCorrected
{
    /**
     * @param  list<string>  $changedFields
     */
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $occupancyId,
        public array $changedFields,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
