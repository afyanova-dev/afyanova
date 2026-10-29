<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when a draft roster entry is scheduled (25-hr-and-workforce-domain.md §7).
 */
final readonly class RosterEntryScheduled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $rosterEntryId,
        public string $facilityId,
        public ?string $departmentId,
        public ?string $unitId,
        public string $staffId,
        public string $shiftPatternId,
        public string $date,
        public string $status,
        public ?string $notes,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
