<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Organizational assignment summary (25-hr-and-workforce-domain.md §3; ADR-206).
 */
final readonly class AssignmentSummary
{
    public function __construct(
        public string $assignmentId,
        public string $tenantId,
        public string $staffId,
        public string $facilityId,
        public ?string $departmentId,
        public ?string $unitId,
        public string $scope,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public bool $isPrimary,
        public string $status,
        public ?string $endReason,
        public int $version,
    ) {}
}
