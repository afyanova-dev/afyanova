<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when an assignment is ended (25-hr-and-workforce-domain.md §11; ADR-206).
 */
final readonly class AssignmentEnded
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $assignmentId,
        public string $staffId,
        public string $facilityId,
        public string $endedDate,
        public ?string $endReason,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
