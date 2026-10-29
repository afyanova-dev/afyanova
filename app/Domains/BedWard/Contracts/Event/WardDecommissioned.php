<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Event;

/**
 * A ward was decommissioned (24-bed-and-ward-management-domain.md §11; ADR-148 D-J).
 *
 * PHI-safe: identifiers only.
 */
final readonly class WardDecommissioned
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $wardId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
