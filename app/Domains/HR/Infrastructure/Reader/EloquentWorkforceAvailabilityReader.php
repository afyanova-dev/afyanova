<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\WorkforceAvailabilitySummary;
use App\Domains\HR\Domain\Model\Assignment;
use App\Domains\HR\Domain\Model\AttendanceSession;
use App\Domains\HR\Domain\Model\LeaveRequest;
use App\Domains\HR\Domain\Model\RosterEntry;
use App\Domains\HR\Domain\Model\Staff;
use App\Domains\HR\Domain\ValueObject\AssignmentStatus;
use App\Domains\HR\Domain\ValueObject\AttendanceSessionStatus;
use App\Domains\HR\Domain\ValueObject\LeaveRequestStatus;
use App\Domains\HR\Domain\ValueObject\RosterEntryStatus;
use App\Domains\HR\Domain\ValueObject\StaffStatus;
use App\Domains\HR\Domain\ValueObject\WorkforceAvailabilityStatus;
use App\Domains\HR\Domain\WorkforceAvailabilityReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Eloquent implementation of WorkforceAvailabilityReader (25-hr-and-workforce-domain.md §6, §7.10).
 *
 * Availability is strictly derived: Roster − Approved Leave − Actual Attendance.
 */
final class EloquentWorkforceAvailabilityReader implements WorkforceAvailabilityReader
{
    /**
     * @return list<WorkforceAvailabilitySummary>
     */
    public function getAvailability(
        string $facilityId,
        ?CarbonImmutable $atTime = null,
        ?string $departmentId = null,
        ?string $unitId = null,
        ?string $cadreId = null,
    ): array {
        $now = $atTime ?? CarbonImmutable::now();
        $dateStr = $now->toDateString();
        $timeStr = $now->format('H:i:s');
        $yesterdayDate = $now->subDay()->toDateString();

        // 1. Fetch active assignments for this facility
        $assignmentsQuery = Assignment::query()
            ->with([
                'staff.person',
                'staff.primaryCadre',
                'staff.primaryPosition',
            ])
            ->where('facility_id', $facilityId)
            ->where('status', AssignmentStatus::ACTIVE->value)
            ->whereHas('staff', fn ($q) => $q->where('status', StaffStatus::ACTIVE->value));

        if ($departmentId !== null) {
            $assignmentsQuery->where('department_id', $departmentId);
        }

        if ($unitId !== null) {
            $assignmentsQuery->where('unit_id', $unitId);
        }

        if ($cadreId !== null) {
            $assignmentsQuery->whereHas('staff', fn ($q) => $q->where('primary_cadre_id', $cadreId));
        }

        /** @var Collection<int, Assignment> $assignments */
        $assignments = $assignmentsQuery
            ->orderByDesc('is_primary')
            ->orderByDesc('effective_from')
            ->get();

        // Deduplicate assignments by staff_id, preferring primary
        $staffAssignments = [];
        foreach ($assignments as $assignment) {
            if (! isset($staffAssignments[$assignment->staff_id])) {
                $staffAssignments[$assignment->staff_id] = $assignment;
            }
        }

        if (empty($staffAssignments)) {
            return [];
        }

        $staffIds = array_keys($staffAssignments);

        // 2. Query active leaves covering $now
        $leaves = LeaveRequest::query()
            ->whereIn('staff_id', $staffIds)
            ->whereIn('status', [LeaveRequestStatus::APPROVED->value, LeaveRequestStatus::TAKEN->value])
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->get()
            ->keyBy('staff_id');

        // 3. Query open attendance sessions
        $sessions = AttendanceSession::query()
            ->where('facility_id', $facilityId)
            ->whereIn('staff_id', $staffIds)
            ->where('status', AttendanceSessionStatus::OPEN->value)
            ->get()
            ->keyBy('staff_id');

        // 4. Query published roster shifts covering $now
        $rosterEntries = RosterEntry::query()
            ->with(['shiftPattern'])
            ->where('facility_id', $facilityId)
            ->whereIn('staff_id', $staffIds)
            ->where('status', RosterEntryStatus::PUBLISHED->value)
            ->where(function ($q) use ($dateStr, $yesterdayDate, $timeStr): void {
                $q->where(function ($sameDay) use ($dateStr, $timeStr): void {
                    $sameDay->where('date', $dateStr)
                        ->where('start_time', '<=', $timeStr)
                        ->where(function ($sub) use ($timeStr): void {
                            $sub->where('end_time', '>=', $timeStr)
                                ->orWhereColumn('start_time', '>', 'end_time');
                        });
                })->orWhere(function ($crossDay) use ($yesterdayDate, $timeStr): void {
                    $crossDay->where('date', $yesterdayDate)
                        ->whereColumn('start_time', '>', 'end_time')
                        ->where('end_time', '>=', $timeStr);
                });
            })
            ->get()
            ->keyBy('staff_id');

        $summaries = [];

        foreach ($staffAssignments as $staffId => $assignment) {
            $staff = $assignment->staff;
            if ($staff === null) {
                continue;
            }

            $person = $staff->person;
            $staffName = $person !== null
                ? trim("{$person->first_name} ".($person->middle_name ? "{$person->middle_name} " : '').$person->last_name)
                : null;

            $rosterEntry = $rosterEntries->get($staffId);
            $session = $sessions->get($staffId);
            $leave = $leaves->get($staffId);

            $status = WorkforceAvailabilityStatus::OFF_DUTY->value;
            $rosterEntryId = null;
            $shiftCode = null;
            $currentSessionId = null;
            $currentLeaveRequestId = null;

            if ($leave !== null) {
                $status = WorkforceAvailabilityStatus::ON_LEAVE->value;
                $currentLeaveRequestId = $leave->id;
            } elseif ($session !== null) {
                $status = WorkforceAvailabilityStatus::ON_SHIFT->value;
                $currentSessionId = $session->id;
                if ($rosterEntry !== null) {
                    $rosterEntryId = $rosterEntry->id;
                    $shiftCode = $rosterEntry->shiftPattern?->code;
                }
            } elseif ($rosterEntry !== null) {
                $status = WorkforceAvailabilityStatus::SCHEDULED_NOT_CLOCKED_IN->value;
                $rosterEntryId = $rosterEntry->id;
                $shiftCode = $rosterEntry->shiftPattern?->code;
            }

            $summaries[] = new WorkforceAvailabilitySummary(
                staffId: $staffId,
                staffNumber: $staff->staff_number,
                staffName: $staffName,
                facilityId: $assignment->facility_id,
                departmentId: $assignment->department_id,
                unitId: $assignment->unit_id,
                cadreId: $staff->primary_cadre_id,
                cadreName: $staff->primaryCadre?->name,
                positionId: $staff->primary_position_id,
                positionTitle: $staff->primaryPosition?->title,
                status: $status,
                rosterEntryId: $rosterEntryId,
                shiftCode: $shiftCode,
                currentSessionId: $currentSessionId,
                currentLeaveRequestId: $currentLeaveRequestId,
            );
        }

        return $summaries;
    }

    public function isStaffAvailable(string $staffId, CarbonImmutable $dateTime): bool
    {
        if ($this->isStaffOnLeave($staffId, $dateTime)) {
            return false;
        }

        /** @var Staff|null $staff */
        $staff = Staff::query()->whereKey($staffId)->first();
        if ($staff === null || $staff->status !== StaffStatus::ACTIVE) {
            return false;
        }

        return Assignment::query()
            ->where('staff_id', $staffId)
            ->where('status', AssignmentStatus::ACTIVE->value)
            ->exists();
    }

    public function isStaffOnLeave(string $staffId, CarbonImmutable $date): bool
    {
        $dateStr = $date->toDateString();

        return LeaveRequest::query()
            ->where('staff_id', $staffId)
            ->whereIn('status', [LeaveRequestStatus::APPROVED->value, LeaveRequestStatus::TAKEN->value])
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->exists();
    }
}
