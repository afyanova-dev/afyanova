<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Event;

/**
 * A bed's status changed (24-bed-and-ward-management-domain.md §11; ADR-148 D-J).
 *
 * PHI-safe: identifiers and coded values only.
 */
final readonly class BedStatusChanged
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $bedId,
        public string $fromStatus,
        public string $toStatus,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
