<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Service;

use App\Domains\Theatre\Domain\Exception\ChecklistGateNotSatisfied;
use App\Domains\Theatre\Domain\Exception\IllegalOperatingRoomTransition;
use App\Domains\Theatre\Domain\Exception\IllegalSurgicalCaseTransition;
use App\Domains\Theatre\Domain\Exception\OperatingRoomNotAvailable;
use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\Model\SurgicalCase;

/**
 * SurgicalCase and OperatingRoom lifecycle rules (17-theatre-domain.md §219,
 * §299; ADR-150 D-D/D-G). Pure domain logic, no I/O.
 *
 * OperatingRoom's own status transitions are not prescribed by any canon
 * invariant (§18: "the exact lifecycle may later be extended") — this
 * service ties them to the case transitions that drive them in T.1:
 * AssignOperatingRoom reserves, StartCase marks in-use, CompleteCase/
 * AbortCase send the room for cleaning, and Cancel/Postpone/NoShow release
 * an unused reservation.
 */
final class SurgicalCaseLifecycle
{
    /** Checklist entries required before MarkCaseReady (ADR-150 D-G). */
    private const READY_CHECKLIST_FLOOR = 1;

    /** Checklist entries required before StartCase (ADR-150 D-G). */
    private const START_CHECKLIST_FLOOR = 2;

    /** @var list<string> Case statuses from which a room may still be (re)assigned. */
    private const ROOM_ASSIGNABLE_STATUSES = [
        SurgicalCase::STATUS_REQUESTED,
        SurgicalCase::STATUS_PLANNED,
        SurgicalCase::STATUS_SCHEDULED,
        SurgicalCase::STATUS_PRE_OP,
    ];

    public function assertCanAssignRoom(string $caseStatus, string $roomId, string $roomStatus): void
    {
        if (! in_array($caseStatus, self::ROOM_ASSIGNABLE_STATUSES, true)) {
            throw IllegalSurgicalCaseTransition::fromStatus('AssignOperatingRoom', $caseStatus);
        }

        if ($roomStatus !== OperatingRoom::STATUS_AVAILABLE) {
            throw OperatingRoomNotAvailable::forStatus($roomId, $roomStatus);
        }
    }

    public function assertCanSchedule(string $caseStatus): void
    {
        if (! in_array($caseStatus, [SurgicalCase::STATUS_REQUESTED, SurgicalCase::STATUS_PLANNED], true)) {
            throw IllegalSurgicalCaseTransition::fromStatus('ScheduleSurgicalCase', $caseStatus);
        }
    }

    public function assertCanReschedule(string $caseStatus): void
    {
        if ($caseStatus !== SurgicalCase::STATUS_SCHEDULED) {
            throw IllegalSurgicalCaseTransition::fromStatus('RescheduleSurgicalCase', $caseStatus);
        }
    }

    public function assertCanStartPreOp(string $caseStatus): void
    {
        if ($caseStatus !== SurgicalCase::STATUS_SCHEDULED) {
            throw IllegalSurgicalCaseTransition::fromStatus('StartPreOp', $caseStatus);
        }
    }

    /**
     * @param  list<array{stage: string, completed_at: string, actor_user_id: string}>  $checklist
     */
    public function assertCanMarkReady(string $caseStatus, ?string $operatingRoomId, array $checklist): void
    {
        if ($caseStatus !== SurgicalCase::STATUS_PRE_OP) {
            throw IllegalSurgicalCaseTransition::fromStatus('MarkCaseReady', $caseStatus);
        }

        if ($operatingRoomId === null) {
            throw IllegalSurgicalCaseTransition::fromStatus('MarkCaseReady (no operating room assigned)', $caseStatus);
        }

        if (count($checklist) < self::READY_CHECKLIST_FLOOR) {
            throw ChecklistGateNotSatisfied::forTransition('MarkCaseReady', self::READY_CHECKLIST_FLOOR, count($checklist));
        }
    }

    /**
     * @param  list<array{stage: string, completed_at: string, actor_user_id: string}>  $checklist
     */
    public function assertCanStart(string $caseStatus, array $checklist): void
    {
        if ($caseStatus !== SurgicalCase::STATUS_READY) {
            throw IllegalSurgicalCaseTransition::fromStatus('StartCase', $caseStatus);
        }

        if (count($checklist) < self::START_CHECKLIST_FLOOR) {
            throw ChecklistGateNotSatisfied::forTransition('StartCase', self::START_CHECKLIST_FLOOR, count($checklist));
        }
    }

    public function assertCanComplete(string $caseStatus): void
    {
        if ($caseStatus !== SurgicalCase::STATUS_IN_PROGRESS) {
            throw IllegalSurgicalCaseTransition::fromStatus('CompleteCase', $caseStatus);
        }
    }

    public function assertCanAbort(string $caseStatus): void
    {
        if ($caseStatus !== SurgicalCase::STATUS_IN_PROGRESS) {
            throw IllegalSurgicalCaseTransition::fromStatus('AbortCase', $caseStatus);
        }
    }

    public function assertCanCancel(string $caseStatus): void
    {
        if (! in_array($caseStatus, [
            SurgicalCase::STATUS_SCHEDULED,
            SurgicalCase::STATUS_PRE_OP,
            SurgicalCase::STATUS_READY,
        ], true)) {
            throw IllegalSurgicalCaseTransition::fromStatus('CancelCase', $caseStatus);
        }
    }

    public function assertCanPostpone(string $caseStatus): void
    {
        if ($caseStatus !== SurgicalCase::STATUS_SCHEDULED) {
            throw IllegalSurgicalCaseTransition::fromStatus('PostponeCase', $caseStatus);
        }
    }

    public function assertCanMarkNoShow(string $caseStatus): void
    {
        if ($caseStatus !== SurgicalCase::STATUS_SCHEDULED) {
            throw IllegalSurgicalCaseTransition::fromStatus('MarkNoShow', $caseStatus);
        }
    }

    public function assertCanAssignTeamMember(string $caseStatus): void
    {
        if (in_array($caseStatus, SurgicalCase::TERMINAL_STATUSES, true)) {
            throw IllegalSurgicalCaseTransition::fromStatus('AssignTeamMember', $caseStatus);
        }
    }

    public function assertCanCompleteChecklistStage(string $caseStatus): void
    {
        if (in_array($caseStatus, SurgicalCase::TERMINAL_STATUSES, true)) {
            throw IllegalSurgicalCaseTransition::fromStatus('CompleteChecklistStage', $caseStatus);
        }
    }

    // --- OperatingRoom (driven by the case transitions above) ------------

    public function assertRoomCanTransition(string $fromStatus, string $toStatus): void
    {
        $allowed = [
            OperatingRoom::STATUS_AVAILABLE => [OperatingRoom::STATUS_RESERVED],
            OperatingRoom::STATUS_RESERVED => [OperatingRoom::STATUS_IN_USE, OperatingRoom::STATUS_AVAILABLE],
            OperatingRoom::STATUS_IN_USE => [OperatingRoom::STATUS_CLEANING],
            OperatingRoom::STATUS_CLEANING => [OperatingRoom::STATUS_AVAILABLE, OperatingRoom::STATUS_MAINTENANCE],
            OperatingRoom::STATUS_MAINTENANCE => [OperatingRoom::STATUS_AVAILABLE, OperatingRoom::STATUS_CLOSED],
            OperatingRoom::STATUS_CLOSED => [OperatingRoom::STATUS_AVAILABLE],
        ];

        if (! in_array($toStatus, $allowed[$fromStatus] ?? [], true)) {
            throw IllegalOperatingRoomTransition::fromStatus("transition to {$toStatus}", $fromStatus);
        }
    }
}
