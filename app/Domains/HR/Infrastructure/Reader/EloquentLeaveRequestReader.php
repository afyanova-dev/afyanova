<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\LeaveCalendarEntry;
use App\Domains\HR\Contracts\Dto\LeaveRequestSummary;
use App\Domains\HR\Domain\Exception\LeaveRequestNotFoundException;
use App\Domains\HR\Domain\LeaveRequestReader;
use App\Domains\HR\Domain\Model\LeaveRequest;
use App\Domains\HR\Domain\ValueObject\LeaveRequestStatus;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of LeaveRequestReader (25-hr-and-workforce-domain.md §6, §10).
 */
final class EloquentLeaveRequestReader implements LeaveRequestReader
{
    public function summary(string $leaveRequestId): LeaveRequestSummary
    {
        /** @var LeaveRequest|null $request */
        $request = LeaveRequest::query()
            ->with(['staff.person', 'leaveType'])
            ->whereKey($leaveRequestId)
            ->first();

        if ($request === null) {
            throw LeaveRequestNotFoundException::forId($leaveRequestId);
        }

        return $this->toSummary($request);
    }

    /**
     * @return list<LeaveRequestSummary>
     */
    public function forStaff(string $staffId, ?string $status = null): array
    {
        $query = LeaveRequest::query()
            ->with(['staff.person', 'leaveType'])
            ->where('staff_id', $staffId);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return array_values($query
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (LeaveRequest $r): LeaveRequestSummary => $this->toSummary($r))
            ->all());
    }

    /**
     * @return list<LeaveRequestSummary>
     */
    public function forFacility(string $facilityId, ?string $status = null): array
    {
        $query = LeaveRequest::query()
            ->with(['staff.person', 'leaveType'])
            ->where('facility_id', $facilityId);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return array_values($query
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (LeaveRequest $r): LeaveRequestSummary => $this->toSummary($r))
            ->all());
    }

    /**
     * @return list<LeaveCalendarEntry>
     */
    public function getCalendar(string $facilityId, CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $startStr = $startDate->toDateString();
        $endStr = $endDate->toDateString();

        $requests = LeaveRequest::query()
            ->with(['staff.person', 'leaveType'])
            ->where('facility_id', $facilityId)
            ->whereIn('status', [LeaveRequestStatus::APPROVED->value, LeaveRequestStatus::TAKEN->value])
            ->where('start_date', '<=', $endStr)
            ->where('end_date', '>=', $startStr)
            ->orderBy('start_date')
            ->get();

        return array_values($requests
            ->map(function (LeaveRequest $r): LeaveCalendarEntry {
                $staff = $r->staff;
                $person = $staff?->person;
                $staffName = $person !== null
                    ? trim("{$person->first_name} ".($person->middle_name ? "{$person->middle_name} " : '').$person->last_name)
                    : null;

                return new LeaveCalendarEntry(
                    leaveRequestId: $r->id,
                    facilityId: $r->facility_id,
                    staffId: $r->staff_id,
                    staffNumber: $staff?->staff_number,
                    staffName: $staffName,
                    leaveTypeCode: $r->leaveType !== null ? $r->leaveType->code : '',
                    startDate: $r->start_date->toDateString(),
                    endDate: $r->end_date->toDateString(),
                    days: (float) $r->days,
                    status: $r->status->value,
                );
            })
            ->all());
    }

    public function getActiveLeaveForStaffOnDate(string $staffId, CarbonImmutable $date): ?LeaveRequestSummary
    {
        $dateStr = $date->toDateString();

        /** @var LeaveRequest|null $request */
        $request = LeaveRequest::query()
            ->with(['staff.person', 'leaveType'])
            ->where('staff_id', $staffId)
            ->whereIn('status', [LeaveRequestStatus::APPROVED->value, LeaveRequestStatus::TAKEN->value])
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->first();

        return $request !== null ? $this->toSummary($request) : null;
    }

    private function toSummary(LeaveRequest $r): LeaveRequestSummary
    {
        $staff = $r->staff;
        $person = $staff?->person;
        $staffName = $person !== null
            ? trim("{$person->first_name} ".($person->middle_name ? "{$person->middle_name} " : '').$person->last_name)
            : null;

        return new LeaveRequestSummary(
            leaveRequestId: $r->id,
            tenantId: $r->tenant_id,
            facilityId: $r->facility_id,
            staffId: $r->staff_id,
            staffNumber: $staff?->staff_number,
            staffName: $staffName,
            leaveTypeId: $r->leave_type_id,
            leaveTypeCode: $r->leaveType?->code,
            leaveTypeName: $r->leaveType?->name,
            startDate: $r->start_date->toDateString(),
            endDate: $r->end_date->toDateString(),
            days: (float) $r->days,
            status: $r->status->value,
            reason: $r->reason,
            reviewerId: $r->reviewer_id,
            reviewedAt: $r->reviewed_at?->toIso8601String(),
            rejectionReason: $r->rejection_reason,
            cancellationReason: $r->cancellation_reason,
            version: (int) $r->version,
        );
    }
}
