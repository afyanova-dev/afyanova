<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\AssignmentReader;
use App\Domains\HR\Domain\EmploymentReader;
use App\Domains\HR\Domain\Exception\StaffHasActiveAssignmentsException;
use App\Domains\HR\Domain\Exception\StaffHasActiveEmploymentException;
use App\Domains\HR\Domain\Exception\StaffHasFutureRosterEntriesException;
use App\Domains\HR\Domain\RosterEntryReader;
use App\Domains\HR\Domain\StaffRepository;

/**
 * Handles TerminateStaff command (ADR-206).
 *
 * Enforces single-aggregate consistency boundary: checks precondition that
 * zero employments are in ACTIVE or SUSPENDED status, zero assignments are ACTIVE,
 * and zero future published roster entries exist (Invariant 7.13; ADR-206 F1).
 * Mutates only Staff; never directly mutates Employment or Assignment records.
 */
final class TerminateStaffHandler
{
    public function __construct(
        private readonly StaffRepository $staff,
        private readonly EmploymentReader $employments,
        private readonly AssignmentReader $assignments,
        private readonly RosterEntryReader $rosterEntries,
    ) {}

    public function __invoke(TerminateStaff $command): void
    {
        $openCount = $this->employments->openEmploymentsCount($command->staffId);

        if ($openCount > 0) {
            throw StaffHasActiveEmploymentException::forStaff($command->staffId, $openCount);
        }

        $openAssignments = $this->assignments->openAssignmentsCount($command->staffId);

        if ($openAssignments > 0) {
            throw StaffHasActiveAssignmentsException::forStaff($command->staffId, $openAssignments);
        }

        $futureRosterCount = $this->rosterEntries->futurePublishedCount($command->staffId);

        if ($futureRosterCount > 0) {
            throw StaffHasFutureRosterEntriesException::forStaff($command->staffId, $futureRosterCount);
        }

        $staff = $this->staff->getByIdForUpdate($command->staffId);

        $staff->terminate($command->terminatedDate, $command->reason);

        $this->staff->save($staff);
    }
}
