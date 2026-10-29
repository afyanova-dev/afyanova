<?php

declare(strict_types=1);

use App\Domains\Theatre\Domain\Exception\ChecklistGateNotSatisfied;
use App\Domains\Theatre\Domain\Exception\IllegalOperatingRoomTransition;
use App\Domains\Theatre\Domain\Exception\IllegalSurgicalCaseTransition;
use App\Domains\Theatre\Domain\Exception\OperatingRoomNotAvailable;
use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;

beforeEach(function () {
    $this->lifecycle = new SurgicalCaseLifecycle;
});

test('assertCanAssignRoom allows only pre-READY case statuses and an AVAILABLE room', function () {
    $this->lifecycle->assertCanAssignRoom(SurgicalCase::STATUS_REQUESTED, 'room-1', OperatingRoom::STATUS_AVAILABLE);
    $this->lifecycle->assertCanAssignRoom(SurgicalCase::STATUS_SCHEDULED, 'room-1', OperatingRoom::STATUS_AVAILABLE);
    $this->lifecycle->assertCanAssignRoom(SurgicalCase::STATUS_PRE_OP, 'room-1', OperatingRoom::STATUS_AVAILABLE);

    expect(fn () => $this->lifecycle->assertCanAssignRoom(SurgicalCase::STATUS_READY, 'room-1', OperatingRoom::STATUS_AVAILABLE))
        ->toThrow(IllegalSurgicalCaseTransition::class);

    expect(fn () => $this->lifecycle->assertCanAssignRoom(SurgicalCase::STATUS_REQUESTED, 'room-1', OperatingRoom::STATUS_RESERVED))
        ->toThrow(OperatingRoomNotAvailable::class);
});

test('assertCanMarkReady requires PRE_OP, an assigned room, and >=1 checklist entry (ADR-150 D-G)', function () {
    $entry = ['stage' => 'BEFORE_INCISION', 'completed_at' => now()->toIso8601String(), 'actor_user_id' => 'u1'];

    $this->lifecycle->assertCanMarkReady(SurgicalCase::STATUS_PRE_OP, 'room-1', [$entry]);

    expect(fn () => $this->lifecycle->assertCanMarkReady(SurgicalCase::STATUS_SCHEDULED, 'room-1', [$entry]))
        ->toThrow(IllegalSurgicalCaseTransition::class);

    expect(fn () => $this->lifecycle->assertCanMarkReady(SurgicalCase::STATUS_PRE_OP, null, [$entry]))
        ->toThrow(IllegalSurgicalCaseTransition::class);

    expect(fn () => $this->lifecycle->assertCanMarkReady(SurgicalCase::STATUS_PRE_OP, 'room-1', []))
        ->toThrow(ChecklistGateNotSatisfied::class);
});

test('assertCanStart requires READY and >=2 checklist entries (ADR-150 D-G)', function () {
    $entry = ['stage' => 'BEFORE_INCISION', 'completed_at' => now()->toIso8601String(), 'actor_user_id' => 'u1'];

    $this->lifecycle->assertCanStart(SurgicalCase::STATUS_READY, [$entry, $entry]);

    expect(fn () => $this->lifecycle->assertCanStart(SurgicalCase::STATUS_READY, [$entry]))
        ->toThrow(ChecklistGateNotSatisfied::class);

    expect(fn () => $this->lifecycle->assertCanStart(SurgicalCase::STATUS_PRE_OP, [$entry, $entry]))
        ->toThrow(IllegalSurgicalCaseTransition::class);
});

test('assertCanCancel allows SCHEDULED, PRE_OP, READY and rejects others', function () {
    $this->lifecycle->assertCanCancel(SurgicalCase::STATUS_SCHEDULED);
    $this->lifecycle->assertCanCancel(SurgicalCase::STATUS_PRE_OP);
    $this->lifecycle->assertCanCancel(SurgicalCase::STATUS_READY);

    foreach ([SurgicalCase::STATUS_REQUESTED, SurgicalCase::STATUS_IN_PROGRESS, SurgicalCase::STATUS_COMPLETED] as $status) {
        expect(fn () => $this->lifecycle->assertCanCancel($status))->toThrow(IllegalSurgicalCaseTransition::class);
    }
});

test('assertCanPostpone and assertCanMarkNoShow allow only SCHEDULED (distinct from cancel, §299 Invariant 15)', function () {
    $this->lifecycle->assertCanPostpone(SurgicalCase::STATUS_SCHEDULED);
    $this->lifecycle->assertCanMarkNoShow(SurgicalCase::STATUS_SCHEDULED);

    expect(fn () => $this->lifecycle->assertCanPostpone(SurgicalCase::STATUS_PRE_OP))
        ->toThrow(IllegalSurgicalCaseTransition::class);
    expect(fn () => $this->lifecycle->assertCanMarkNoShow(SurgicalCase::STATUS_READY))
        ->toThrow(IllegalSurgicalCaseTransition::class);
});

test('assertCanAbort allows only IN_PROGRESS (distinct from cancel, §299 Invariant 16)', function () {
    $this->lifecycle->assertCanAbort(SurgicalCase::STATUS_IN_PROGRESS);

    expect(fn () => $this->lifecycle->assertCanAbort(SurgicalCase::STATUS_READY))
        ->toThrow(IllegalSurgicalCaseTransition::class);
});

test('assertCanAssignTeamMember and assertCanCompleteChecklistStage reject terminal statuses', function () {
    $this->lifecycle->assertCanAssignTeamMember(SurgicalCase::STATUS_REQUESTED);
    $this->lifecycle->assertCanCompleteChecklistStage(SurgicalCase::STATUS_IN_PROGRESS);

    foreach (SurgicalCase::TERMINAL_STATUSES as $status) {
        expect(fn () => $this->lifecycle->assertCanAssignTeamMember($status))->toThrow(IllegalSurgicalCaseTransition::class);
        expect(fn () => $this->lifecycle->assertCanCompleteChecklistStage($status))->toThrow(IllegalSurgicalCaseTransition::class);
    }
});

test('assertRoomCanTransition enforces the OperatingRoom state graph', function () {
    $this->lifecycle->assertRoomCanTransition(OperatingRoom::STATUS_AVAILABLE, OperatingRoom::STATUS_RESERVED);
    $this->lifecycle->assertRoomCanTransition(OperatingRoom::STATUS_RESERVED, OperatingRoom::STATUS_IN_USE);
    $this->lifecycle->assertRoomCanTransition(OperatingRoom::STATUS_IN_USE, OperatingRoom::STATUS_CLEANING);
    $this->lifecycle->assertRoomCanTransition(OperatingRoom::STATUS_CLEANING, OperatingRoom::STATUS_AVAILABLE);

    expect(fn () => $this->lifecycle->assertRoomCanTransition(OperatingRoom::STATUS_AVAILABLE, OperatingRoom::STATUS_IN_USE))
        ->toThrow(IllegalOperatingRoomTransition::class);
});
