<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when an assignment's scope, dates, or primary status are amended (25-hr-and-workforce-domain.md §11; ADR-206).
 */
final readonly class AssignmentAmended
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $assignmentId,
        public string $staffId,
        public string $facilityId,
        public ?string $departmentId,
        public ?string $unitId,
        public string $scope,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public bool $isPrimary,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
