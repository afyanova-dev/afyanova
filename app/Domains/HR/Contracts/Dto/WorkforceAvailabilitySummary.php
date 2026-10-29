<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Real-time derived workforce availability summary (25-hr-and-workforce-domain.md §6, §7.10).
 *
 * Availability is strictly derived: Roster − Approved Leave − Actual Attendance.
 */
final readonly class WorkforceAvailabilitySummary
{
    public function __construct(
        public string $staffId,
        public ?string $staffNumber,
        public ?string $staffName,
        public string $facilityId,
        public ?string $departmentId,
        public ?string $unitId,
        public ?string $cadreId,
        public ?string $cadreName,
        public ?string $positionId,
        public ?string $positionTitle,
        public string $status, // ON_SHIFT, SCHEDULED_NOT_CLOCKED_IN, ON_LEAVE, OFF_DUTY
        public ?string $rosterEntryId,
        public ?string $shiftCode,
        public ?string $currentSessionId,
        public ?string $currentLeaveRequestId,
    ) {}
}
