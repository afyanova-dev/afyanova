<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\AttendanceEventRepository;
use App\Domains\HR\Domain\AttendanceSessionRepository;
use App\Domains\HR\Domain\Exception\InvalidOrganizationStructureException;
use App\Domains\HR\Domain\Model\AttendanceEvent;
use App\Domains\HR\Domain\Model\AttendanceSession;
use App\Domains\HR\Domain\RosterEntryRepository;
use App\Domains\HR\Domain\StaffRepository;
use App\Domains\HR\Domain\ValueObject\AttendanceDirection;
use App\Domains\HR\Domain\ValueObject\AttendanceExceptionType;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Handles IngestAttendanceEvent command (25-hr-and-workforce-domain.md §6, §7.11).
 *
 * Appends raw immutable event and incrementally updates or pairs active attendance session.
 */
final class IngestAttendanceEventHandler
{
    public function __construct(
        private readonly AttendanceEventRepository $events,
        private readonly AttendanceSessionRepository $sessions,
        private readonly StaffRepository $staff,
        private readonly RosterEntryRepository $rosterEntries,
        private readonly OrganizationApi $org,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(IngestAttendanceEvent $command): void
    {
        // 1. Invariant 7.11: Idempotency on device sync / offline retries
        if ($command->externalEventId !== null && $this->events->existsByExternalEventId($command->externalEventId)) {
            return;
        }

        // 2. Validate staff exists
        $this->staff->getById($command->staffId);

        // 3. Validate facility exists
        if (! $this->org->facilityExists($command->facilityId)) {
            throw InvalidOrganizationStructureException::facilityNotFound($command->facilityId);
        }

        // 4. Ingest and persist raw immutable event
        $event = AttendanceEvent::ingest(
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            staffId: $command->staffId,
            source: $command->source,
            direction: $command->direction,
            occurredAt: $command->occurredAt,
            deviceRef: $command->deviceRef,
            externalEventId: $command->externalEventId,
            rawPayload: $command->rawPayload,
            notes: $command->notes,
            attendanceEventId: $command->attendanceEventId,
        );

        $this->events->save($event);

        // 5. Incrementally manage attendance sessions
        $this->pairEventToSession($event);
    }

    private function pairEventToSession(AttendanceEvent $event): void
    {
        switch ($event->direction) {
            case AttendanceDirection::IN:
                $this->handleClockIn($event);
                break;

            case AttendanceDirection::OUT:
                $this->handleClockOut($event);
                break;

            case AttendanceDirection::BREAK_OUT:
                $this->handleBreakOut($event);
                break;

            case AttendanceDirection::BREAK_IN:
                $this->handleBreakIn($event);
                break;
        }
    }

    private function handleClockIn(AttendanceEvent $event): void
    {
        // If an open session already exists without clock out, mark it as MISSING_OUT and close it
        $openSession = $this->sessions->findOpenSessionForStaffForUpdate($event->staff_id);
        if ($openSession !== null) {
            $openSession->close($event->occurred_at, $event->id, AttendanceExceptionType::MISSING_OUT);
            $this->sessions->save($openSession);
        }

        // Check if there is a scheduled roster entry for today
        $rosterEntry = $this->rosterEntries->findPublishedForStaffOnDate($event->staff_id, $event->occurred_at);
        $exception = AttendanceExceptionType::NONE;
        $rosterEntryId = $rosterEntry?->id;

        if ($rosterEntry !== null) {
            $scheduledStart = CarbonImmutable::parse($rosterEntry->date->toDateString().' '.$rosterEntry->start_time);
            if ($event->occurred_at->isAfter($scheduledStart->addMinutes(15))) {
                $exception = AttendanceExceptionType::LATE;
            }
        }

        $session = AttendanceSession::start(
            tenantId: $event->tenant_id,
            facilityId: $event->facility_id,
            staffId: $event->staff_id,
            startedAt: $event->occurred_at,
            startEventId: $event->id,
            rosterEntryId: $rosterEntryId,
            exception: $exception,
        );

        $this->sessions->save($session);
    }

    private function handleClockOut(AttendanceEvent $event): void
    {
        $openSession = $this->sessions->findOpenSessionForStaffForUpdate($event->staff_id);

        if ($openSession !== null) {
            $exception = $openSession->exception;

            if ($openSession->roster_entry_id !== null) {
                $rosterEntry = $this->rosterEntries->getById($openSession->roster_entry_id);
                $scheduledEnd = CarbonImmutable::parse($rosterEntry->date->toDateString().' '.$rosterEntry->end_time);

                if ($event->occurred_at->isBefore($scheduledEnd->subMinutes(15))) {
                    $exception = AttendanceExceptionType::EARLY_LEAVE;
                } elseif ($event->occurred_at->isAfter($scheduledEnd->addMinutes(30))) {
                    $exception = AttendanceExceptionType::OVERTIME;
                }
            }

            $openSession->close($event->occurred_at, $event->id, $exception);
            $this->sessions->save($openSession);
        } else {
            // Missing clock-in: clock-out without prior clock-in
            $session = AttendanceSession::start(
                tenantId: $event->tenant_id,
                facilityId: $event->facility_id,
                staffId: $event->staff_id,
                startedAt: $event->occurred_at,
                startEventId: $event->id,
                exception: AttendanceExceptionType::MISSING_IN,
            );
            $session->close($event->occurred_at, $event->id, AttendanceExceptionType::MISSING_IN);
            $this->sessions->save($session);
        }
    }

    private function handleBreakOut(AttendanceEvent $event): void
    {
        $openSession = $this->sessions->findOpenSessionForStaffForUpdate($event->staff_id);
        if ($openSession !== null) {
            $openSession->addEvent($event->id);
            $this->sessions->save($openSession);
        }
    }

    private function handleBreakIn(AttendanceEvent $event): void
    {
        $openSession = $this->sessions->findOpenSessionForStaffForUpdate($event->staff_id);
        if ($openSession !== null) {
            // Find last BREAK_OUT event from session's source events
            $sourceEventIds = array_reverse($openSession->source_event_ids);
            foreach ($sourceEventIds as $priorEventId) {
                $priorEvent = $this->events->getById($priorEventId);
                if ($priorEvent->direction === AttendanceDirection::BREAK_OUT) {
                    $breakMinutes = (int) $priorEvent->occurred_at->diffInMinutes($event->occurred_at);
                    $openSession->addBreakMinutes(max(0, $breakMinutes));
                    break;
                }
            }

            $openSession->addEvent($event->id);
            $this->sessions->save($openSession);
        }
    }
}
