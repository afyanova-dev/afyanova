<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\EmploymentRepository;
use App\Domains\HR\Domain\Exception\DuplicateActiveEmploymentException;
use App\Domains\HR\Domain\Exception\StaffNotEligibleForEmploymentException;
use App\Domains\HR\Domain\StaffRepository;
use App\Domains\HR\Domain\ValueObject\StaffStatus;

/**
 * Handles StartEmployment command (ADR-206).
 *
 * Transitions DRAFT → ACTIVE, enforces Invariant 7.4, and emits EmploymentStarted.
 */
final class StartEmploymentHandler
{
    public function __construct(
        private readonly EmploymentRepository $employments,
        private readonly StaffRepository $staff,
    ) {}

    public function __invoke(StartEmployment $command): void
    {
        $employment = $this->employments->getByIdForUpdate($command->employmentId);

        // Assert staff is eligible
        $staff = $this->staff->getById($employment->staff_id);
        if ($staff->status === StaffStatus::TERMINATED || $staff->status === StaffStatus::SUSPENDED) {
            throw StaffNotEligibleForEmploymentException::forStaff($staff->id, $staff->status);
        }

        // Invariant 7.4: at most one active employment per staff member
        $active = $this->employments->getActiveEmploymentForStaff($employment->staff_id);
        if ($active !== null && $active->id !== $employment->id) {
            throw DuplicateActiveEmploymentException::forStaff($employment->staff_id, $active->id);
        }

        $employment->start($command->startDate);

        $this->employments->save($employment);
    }
}
