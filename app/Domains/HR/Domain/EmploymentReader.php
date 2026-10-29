<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\EmploymentSummary;

/**
 * Read-side port for Employment queries (25-hr-and-workforce-domain.md §6; ADR-206).
 */
interface EmploymentReader
{
    public function summary(string $employmentId): EmploymentSummary;

    public function activeEmploymentForStaff(string $staffId): ?EmploymentSummary;

    /**
     * Number of open employments (status in ACTIVE, SUSPENDED) for a staff member.
     * Used by TerminateStaffHandler to enforce Invariant 7.13.
     */
    public function openEmploymentsCount(string $staffId): int;

    /**
     * @return list<EmploymentSummary>
     */
    public function forStaff(string $staffId): array;
}
