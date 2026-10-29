<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Event;

/**
 * A bed was assigned to an encounter (24-bed-and-ward-management-domain.md §11; ADR-148 D-J).
 *
 * PHI-safe: identifiers and timestamps only.
 */
final readonly class BedAssigned
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $bedId,
        public string $encounterId,
        public string $occupancyId,
        public string $startedAt,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
