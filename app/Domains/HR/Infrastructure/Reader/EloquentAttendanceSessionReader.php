<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\AttendanceSessionSummary;
use App\Domains\HR\Contracts\Dto\TimesheetDaySummary;
use App\Domains\HR\Contracts\Dto\TimesheetSummary;
use App\Domains\HR\Domain\AttendanceSessionReader;
use App\Domains\HR\Domain\Exception\AttendanceSessionNotFoundException;
use App\Domains\HR\Domain\Exception\StaffNotFoundException;
use App\Domains\HR\Domain\Model\AttendanceSession;
use App\Domains\HR\Domain\Model\RosterEntry;
use App\Domains\HR\Domain\Model\Staff;
use App\Domains\HR\Domain\ValueObject\AttendanceExceptionType;
use App\Domains\HR\Domain\ValueObject\RosterEntryStatus;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of AttendanceSessionReader (25-hr-and-workforce-domain.md §6, §10).
 */
final class EloquentAttendanceSessionReader implements AttendanceSessionReader
{
    public function summary(string $sessionId): AttendanceSessionSummary
    {
        /** @var AttendanceSession|null $session */
        $session = AttendanceSession::query()
            ->with(['staff.person'])
            ->whereKey($sessionId)
            ->first();

        if ($session === null) {
            throw AttendanceSessionNotFoundException::forId($sessionId);
        }

        return $this->toSummary($session);
    }

    /**
     * @return list<AttendanceSessionSummary>
     */
    public function forStaff(
        string $staffId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?string $status = null,
    ): array {
        $query = AttendanceSession::query()
            ->with(['staff.person'])
            ->where('staff_id', $staffId)
            ->orderBy('started_at', 'desc');

        if ($from !== null) {
            $query->where('started_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('started_at', '<=', $to);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        /** @var list<AttendanceSession> $sessions */
        $sessions = $query->get()->all();

        return array_map(fn (AttendanceSession $s): AttendanceSessionSummary => $this->toSummary($s), $sessions);
    }

    /**
     * @return list<AttendanceSessionSummary>
     */
    public function forFacility(
        string $facilityId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?string $status = null,
    ): array {
        $query = AttendanceSession::query()
            ->with(['staff.person'])
            ->where('facility_id', $facilityId)
            ->orderBy('started_at', 'desc');

        if ($from !== null) {
            $query->where('started_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('started_at', '<=', $to);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        /** @var list<AttendanceSession> $sessions */
        $sessions = $query->get()->all();

        return array_map(fn (AttendanceSession $s): AttendanceSessionSummary => $this->toSummary($s), $sessions);
    }

    /**
     * @return list<AttendanceSessionSummary>
     */
    public function getExceptions(
        string $facilityId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?string $staffId = null,
    ): array {
        $query = AttendanceSession::query()
            ->with(['staff.person'])
            ->where('facility_id', $facilityId)
            ->where('exception', '!=', AttendanceExceptionType::NONE->value)
            ->orderBy('started_at', 'desc');

        if ($staffId !== null) {
            $query->where('staff_id', $staffId);
        }

        if ($from !== null) {
            $query->where('started_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('started_at', '<=', $to);
        }

        /** @var list<AttendanceSession> $sessions */
        $sessions = $query->get()->all();

        return array_map(fn (AttendanceSession $s): AttendanceSessionSummary => $this->toSummary($s), $sessions);
    }

    public function getTimesheet(
        string $staffId,
        string $facilityId,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): TimesheetSummary {
        /** @var Staff|null $staff */
        $staff = Staff::query()->with('person')->whereKey($staffId)->first();
        if ($staff === null) {
            throw StaffNotFoundException::forId($staffId);
        }

        $staffName = $staff->person !== null
            ? trim($staff->person->first_name.' '.$staff->person->last_name)
            : null;

        $start = $startDate->startOfDay();
        $end = $endDate->endOfDay();

        /** @var list<RosterEntry> $rosterEntries */
        $rosterEntries = RosterEntry::query()
            ->where('staff_id', $staffId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('status', [RosterEntryStatus::CANCELLED->value, RosterEntryStatus::SWAPPED->value])
            ->get()
            ->all();

        /** @var list<AttendanceSession> $sessions */
        $sessions = AttendanceSession::query()
            ->where('staff_id', $staffId)
            ->whereBetween('started_at', [$start, $end])
            ->get()
            ->all();

        $days = [];
        $totalPlannedMinutes = 0;
        $totalWorkedMinutes = 0;
        $totalBreakMinutes = 0;
        $totalOvertimeMinutes = 0;

        $current = $start;
        $endDay = $end->startOfDay();

        while ($current->lessThanOrEqualTo($endDay)) {
            $dateStr = $current->toDateString();

            // Match roster entries on this date
            $dayRosterEntries = array_filter(
                $rosterEntries,
                fn (RosterEntry $r): bool => $r->date->toDateString() === $dateStr
            );

            $plannedMinutes = 0;
            $rosterEntryIds = [];
            foreach ($dayRosterEntries as $entry) {
                $rosterEntryIds[] = $entry->id;
                $start = CarbonImmutable::parse($dateStr.' '.$entry->start_time);
                $finish = CarbonImmutable::parse($dateStr.' '.$entry->end_time);
                if ($finish->lessThan($start)) {
                    $finish = $finish->addDay();
                }
                $plannedMinutes += (int) $start->diffInMinutes($finish);
            }

            // Match sessions on this date
            $daySessions = array_filter(
                $sessions,
                fn (AttendanceSession $s): bool => $s->started_at->toDateString() === $dateStr
            );

            $workedMinutes = 0;
            $breakMinutes = 0;
            $sessionIds = [];
            $exception = AttendanceExceptionType::NONE->value;
            $status = 'OFF';

            if ($daySessions !== []) {
                $status = 'ATTENDED';
                foreach ($daySessions as $session) {
                    $sessionIds[] = $session->id;
                    $workedMinutes += $session->worked_minutes;
                    $breakMinutes += $session->break_minutes;
                    if ($session->exception !== AttendanceExceptionType::NONE) {
                        $exception = $session->exception->value;
                    }
                }
            } elseif ($plannedMinutes > 0) {
                $status = 'ABSENT';
            }

            $overtimeMinutes = max(0, $workedMinutes - $plannedMinutes);

            $totalPlannedMinutes += $plannedMinutes;
            $totalWorkedMinutes += $workedMinutes;
            $totalBreakMinutes += $breakMinutes;
            $totalOvertimeMinutes += $overtimeMinutes;

            $days[] = new TimesheetDaySummary(
                date: $dateStr,
                plannedMinutes: $plannedMinutes,
                workedMinutes: $workedMinutes,
                breakMinutes: $breakMinutes,
                overtimeMinutes: $overtimeMinutes,
                status: $status,
                exception: $exception,
                sessionIds: $sessionIds,
                rosterEntryIds: $rosterEntryIds,
            );

            $current = $current->addDay();
        }

        return new TimesheetSummary(
            staffId: $staffId,
            staffNumber: $staff->staff_number,
            staffName: $staffName !== '' ? $staffName : null,
            facilityId: $facilityId,
            startDate: $startDate->toDateString(),
            endDate: $endDate->toDateString(),
            totalPlannedMinutes: $totalPlannedMinutes,
            totalWorkedMinutes: $totalWorkedMinutes,
            totalBreakMinutes: $totalBreakMinutes,
            totalOvertimeMinutes: $totalOvertimeMinutes,
            days: $days,
        );
    }

    private function toSummary(AttendanceSession $session): AttendanceSessionSummary
    {
        $staffName = $session->staff?->person !== null
            ? trim($session->staff->person->first_name.' '.$session->staff->person->last_name)
            : null;

        return new AttendanceSessionSummary(
            attendanceSessionId: $session->id,
            tenantId: $session->tenant_id,
            facilityId: $session->facility_id,
            staffId: $session->staff_id,
            staffNumber: $session->staff?->staff_number,
            staffName: $staffName !== '' ? $staffName : null,
            rosterEntryId: $session->roster_entry_id,
            startedAt: $session->started_at->toIso8601String(),
            endedAt: $session->ended_at?->toIso8601String(),
            breakMinutes: $session->break_minutes,
            workedMinutes: $session->worked_minutes,
            exception: $session->exception->value,
            sourceEventIds: $session->source_event_ids,
            status: $session->status->value,
            reconciliationNote: $session->reconciliation_note,
            reconciledBy: $session->reconciled_by,
            reconciledAt: $session->reconciled_at?->toIso8601String(),
            version: $session->version,
        );
    }
}
