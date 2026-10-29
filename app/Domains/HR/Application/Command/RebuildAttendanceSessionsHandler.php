<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\AttendanceEventRepository;
use App\Domains\HR\Domain\AttendanceSessionRepository;
use App\Domains\HR\Domain\Model\AttendanceSession;
use App\Domains\HR\Domain\RosterEntryRepository;
use App\Domains\HR\Domain\StaffRepository;
use App\Domains\HR\Domain\ValueObject\AttendanceDirection;
use App\Domains\HR\Domain\ValueObject\AttendanceExceptionType;
use Carbon\CarbonImmutable;

/**
 * Handles RebuildAttendanceSessions command (25-hr-and-workforce-domain.md §6, §7.11).
 *
 * Reconstructs attendance sessions deterministically from the chronological event stream.
 */
final class RebuildAttendanceSessionsHandler
{
    public function __construct(
        private readonly AttendanceSessionRepository $sessions,
        private readonly AttendanceEventRepository $events,
        private readonly StaffRepository $staff,
        private readonly RosterEntryRepository $rosterEntries,
    ) {}

    public function __invoke(RebuildAttendanceSessions $command): void
    {
        $this->staff->getById($command->staffId);

        // 1. Delete all unreconciled sessions in the period (reconciled sessions remain untouched)
        $this->sessions->deleteUnreconciledForStaffAndPeriod(
            $command->staffId,
            $command->startDate,
            $command->endDate
        );

        // 2. Fetch existing reconciled sessions in the period to avoid double processing
        $existingSessions = $this->sessions->forStaffAndPeriod(
            $command->staffId,
            $command->startDate,
            $command->endDate
        );
        $reconciledSessions = array_filter(
            $existingSessions,
            fn (AttendanceSession $s): bool => $s->status->isReconciled()
        );

        // 3. Fetch all raw events in chronological order
        $eventStream = $this->events->forStaffChronological(
            $command->staffId,
            $command->startDate,
            $command->endDate
        );

        // 4. Sequentially pair events into sessions
        /** @var AttendanceSession|null $currentSession */
        $currentSession = null;

        foreach ($eventStream as $event) {
            // Skip events already covered by an audited reconciled session
            $isCovered = false;
            foreach ($reconciledSessions as $rs) {
                if (in_array($event->id, $rs->source_event_ids, true)) {
                    $isCovered = true;
                    break;
                }
                if ($rs->ended_at !== null && $event->occurred_at->betweenIncluded($rs->started_at, $rs->ended_at)) {
                    $isCovered = true;
                    break;
                }
            }

            if ($isCovered) {
                continue;
            }

            switch ($event->direction) {
                case AttendanceDirection::IN:
                    if ($currentSession !== null) {
                        $currentSession->close($event->occurred_at, $event->id, AttendanceExceptionType::MISSING_OUT);
                        $this->sessions->save($currentSession);
                    }

                    $rosterEntry = $this->rosterEntries->findPublishedForStaffOnDate($event->staff_id, $event->occurred_at);
                    $exception = AttendanceExceptionType::NONE;
                    $rosterEntryId = $rosterEntry?->id;

                    if ($rosterEntry !== null) {
                        $scheduledStart = CarbonImmutable::parse($rosterEntry->date->toDateString().' '.$rosterEntry->start_time);
                        if ($event->occurred_at->isAfter($scheduledStart->addMinutes(15))) {
                            $exception = AttendanceExceptionType::LATE;
                        }
                    }

                    $currentSession = AttendanceSession::start(
                        tenantId: $event->tenant_id,
                        facilityId: $event->facility_id,
                        staffId: $event->staff_id,
                        startedAt: $event->occurred_at,
                        startEventId: $event->id,
                        rosterEntryId: $rosterEntryId,
                        exception: $exception,
                    );
                    break;

                case AttendanceDirection::OUT:
                    if ($currentSession !== null) {
                        $exception = $currentSession->exception;

                        if ($currentSession->roster_entry_id !== null) {
                            $rosterEntry = $this->rosterEntries->getById($currentSession->roster_entry_id);
                            $scheduledEnd = CarbonImmutable::parse($rosterEntry->date->toDateString().' '.$rosterEntry->end_time);

                            if ($event->occurred_at->isBefore($scheduledEnd->subMinutes(15))) {
                                $exception = AttendanceExceptionType::EARLY_LEAVE;
                            } elseif ($event->occurred_at->isAfter($scheduledEnd->addMinutes(30))) {
                                $exception = AttendanceExceptionType::OVERTIME;
                            }
                        }

                        $currentSession->close($event->occurred_at, $event->id, $exception);
                        $this->sessions->save($currentSession);
                        $currentSession = null;
                    } else {
                        $orphanSession = AttendanceSession::start(
                            tenantId: $event->tenant_id,
                            facilityId: $event->facility_id,
                            staffId: $event->staff_id,
                            startedAt: $event->occurred_at,
                            startEventId: $event->id,
                            exception: AttendanceExceptionType::MISSING_IN,
                        );
                        $orphanSession->close($event->occurred_at, $event->id, AttendanceExceptionType::MISSING_IN);
                        $this->sessions->save($orphanSession);
                    }
                    break;

                case AttendanceDirection::BREAK_OUT:
                    if ($currentSession !== null) {
                        $currentSession->addEvent($event->id);
                    }
                    break;

                case AttendanceDirection::BREAK_IN:
                    if ($currentSession !== null) {
                        $sourceEventIds = array_reverse($currentSession->source_event_ids);
                        foreach ($sourceEventIds as $priorEventId) {
                            $priorEvent = $this->events->getById($priorEventId);
                            if ($priorEvent->direction === AttendanceDirection::BREAK_OUT) {
                                $breakMinutes = (int) $priorEvent->occurred_at->diffInMinutes($event->occurred_at);
                                $currentSession->addBreakMinutes(max(0, $breakMinutes));
                                break;
                            }
                        }
                        $currentSession->addEvent($event->id);
                    }
                    break;
            }
        }

        if ($currentSession !== null) {
            $this->sessions->save($currentSession);
        }
    }
}
