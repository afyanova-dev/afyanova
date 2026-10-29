<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\AssignmentSummary;

/**
 * Read-side port for Assignment queries (25-hr-and-workforce-domain.md §6, §10; ADR-206).
 */
interface AssignmentReader
{
    public function summary(string $assignmentId): AssignmentSummary;

    /**
     * Number of open assignments (status = ACTIVE) for a staff member.
     * Used by TerminateStaffHandler to enforce Invariant 7.13.
     */
    public function openAssignmentsCount(string $staffId): int;

    /**
     * @return list<AssignmentSummary>
     */
    public function getActiveAssignments(?string $facilityId = null, ?string $departmentId = null, ?string $unitId = null): array;

    /**
     * @return list<AssignmentSummary>
     */
    public function forStaff(string $staffId): array;
}
