<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Event;

/**
 * A bed was created (24-bed-and-ward-management-domain.md §11; ADR-148 D-J).
 *
 * PHI-safe: identifiers and coded values only.
 */
final readonly class BedCreated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $bedId,
        public string $wardId,
        public ?string $roomId,
        public string $bedClass,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
