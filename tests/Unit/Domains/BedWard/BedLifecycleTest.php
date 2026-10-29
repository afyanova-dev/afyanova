<?php

declare(strict_types=1);

use App\Domains\BedWard\Domain\Exception\IllegalBedReservationTransition;
use App\Domains\BedWard\Domain\Exception\IllegalBedTransition;
use App\Domains\BedWard\Domain\Exception\IllegalOccupancyTransition;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Domains\BedWard\Domain\Service\BedLifecycle;

beforeEach(function () {
    $this->lifecycle = new BedLifecycle;
});

test('assertCanAssign allows only AVAILABLE and rejects other statuses', function () {
    $this->lifecycle->assertCanAssign(Bed::STATUS_AVAILABLE);

    $invalidStatuses = [
        Bed::STATUS_OCCUPIED,
        Bed::STATUS_RESERVED,
        Bed::STATUS_CLEANING,
        Bed::STATUS_BLOCKED,
        Bed::STATUS_MAINTENANCE,
        Bed::STATUS_DECOMMISSIONED,
    ];

    foreach ($invalidStatuses as $status) {
        expect(fn () => $this->lifecycle->assertCanAssign($status))
            ->toThrow(IllegalBedTransition::class);
    }
});

test('assertCanRelease requires ACTIVE occupancy and OCCUPIED bed', function () {
    $this->lifecycle->assertCanRelease(BedOccupancy::STATUS_ACTIVE, Bed::STATUS_OCCUPIED);

    expect(fn () => $this->lifecycle->assertCanRelease(BedOccupancy::STATUS_ENDED, Bed::STATUS_OCCUPIED))
        ->toThrow(IllegalOccupancyTransition::class);

    expect(fn () => $this->lifecycle->assertCanRelease(BedOccupancy::STATUS_ACTIVE, Bed::STATUS_AVAILABLE))
        ->toThrow(IllegalBedTransition::class);
});

test('assertCanBlock allows AVAILABLE and CLEANING and rejects OCCUPIED and others', function () {
    $this->lifecycle->assertCanBlock(Bed::STATUS_AVAILABLE);
    $this->lifecycle->assertCanBlock(Bed::STATUS_CLEANING);

    expect(fn () => $this->lifecycle->assertCanBlock(Bed::STATUS_OCCUPIED))
        ->toThrow(IllegalBedTransition::class);
    expect(fn () => $this->lifecycle->assertCanBlock(Bed::STATUS_MAINTENANCE))
        ->toThrow(IllegalBedTransition::class);
});

test('assertCanUnblock allows BLOCKED and MAINTENANCE and rejects other statuses', function () {
    $this->lifecycle->assertCanUnblock(Bed::STATUS_BLOCKED);
    $this->lifecycle->assertCanUnblock(Bed::STATUS_MAINTENANCE);

    $invalidStatuses = [
        Bed::STATUS_AVAILABLE,
        Bed::STATUS_OCCUPIED,
        Bed::STATUS_RESERVED,
        Bed::STATUS_CLEANING,
        Bed::STATUS_DECOMMISSIONED,
    ];

    foreach ($invalidStatuses as $status) {
        expect(fn () => $this->lifecycle->assertCanUnblock($status))
            ->toThrow(IllegalBedTransition::class);
    }
});

test('assertCanMarkCleaning allows AVAILABLE and CLEANING (no-op); rejects OCCUPIED and every other status (post-audit fix)', function () {
    $this->lifecycle->assertCanMarkCleaning(Bed::STATUS_AVAILABLE);
    $this->lifecycle->assertCanMarkCleaning(Bed::STATUS_CLEANING);

    // OCCUPIED is deliberately rejected: the only sanctioned OCCUPIED -> CLEANING
    // path is ReleaseBed, which ends the occupancy first. A standalone
    // MarkBedCleaning on an OCCUPIED bed would strand an ACTIVE occupancy row
    // behind a bed whose status no longer reflects it.
    expect(fn () => $this->lifecycle->assertCanMarkCleaning(Bed::STATUS_OCCUPIED))
        ->toThrow(IllegalBedTransition::class);
    expect(fn () => $this->lifecycle->assertCanMarkCleaning(Bed::STATUS_RESERVED))
        ->toThrow(IllegalBedTransition::class);
    expect(fn () => $this->lifecycle->assertCanMarkCleaning(Bed::STATUS_BLOCKED))
        ->toThrow(IllegalBedTransition::class);
    expect(fn () => $this->lifecycle->assertCanMarkCleaning(Bed::STATUS_DECOMMISSIONED))
        ->toThrow(IllegalBedTransition::class);
});

test('assertCanMarkReady allows only CLEANING and rejects other statuses', function () {
    $this->lifecycle->assertCanMarkReady(Bed::STATUS_CLEANING);

    $invalidStatuses = [
        Bed::STATUS_AVAILABLE,
        Bed::STATUS_OCCUPIED,
        Bed::STATUS_RESERVED,
        Bed::STATUS_BLOCKED,
        Bed::STATUS_MAINTENANCE,
        Bed::STATUS_DECOMMISSIONED,
    ];

    foreach ($invalidStatuses as $status) {
        expect(fn () => $this->lifecycle->assertCanMarkReady($status))
            ->toThrow(IllegalBedTransition::class);
    }
});

test('assertCanDecommission rejects OCCUPIED beds and allows others', function () {
    $this->lifecycle->assertCanDecommission(Bed::STATUS_AVAILABLE);
    $this->lifecycle->assertCanDecommission(Bed::STATUS_CLEANING);
    $this->lifecycle->assertCanDecommission(Bed::STATUS_BLOCKED);
    $this->lifecycle->assertCanDecommission(Bed::STATUS_MAINTENANCE);

    expect(fn () => $this->lifecycle->assertCanDecommission(Bed::STATUS_OCCUPIED))
        ->toThrow(IllegalBedTransition::class);
});

test('assertCanTransfer validates ACTIVE occupancy, fromBed OCCUPIED, toBed AVAILABLE', function () {
    $this->lifecycle->assertCanTransfer(BedOccupancy::STATUS_ACTIVE, Bed::STATUS_OCCUPIED, Bed::STATUS_AVAILABLE);

    expect(fn () => $this->lifecycle->assertCanTransfer(BedOccupancy::STATUS_ENDED, Bed::STATUS_OCCUPIED, Bed::STATUS_AVAILABLE))
        ->toThrow(IllegalOccupancyTransition::class);

    expect(fn () => $this->lifecycle->assertCanTransfer(BedOccupancy::STATUS_ACTIVE, Bed::STATUS_AVAILABLE, Bed::STATUS_AVAILABLE))
        ->toThrow(IllegalBedTransition::class);

    expect(fn () => $this->lifecycle->assertCanTransfer(BedOccupancy::STATUS_ACTIVE, Bed::STATUS_OCCUPIED, Bed::STATUS_OCCUPIED))
        ->toThrow(IllegalBedTransition::class);
});

test('assertCanHold requires a REQUESTED reservation and an AVAILABLE bed (ADR-149 D-B)', function () {
    $this->lifecycle->assertCanHold(BedReservation::STATUS_REQUESTED, Bed::STATUS_AVAILABLE);

    expect(fn () => $this->lifecycle->assertCanHold(BedReservation::STATUS_HELD, Bed::STATUS_AVAILABLE))
        ->toThrow(IllegalBedReservationTransition::class);

    expect(fn () => $this->lifecycle->assertCanHold(BedReservation::STATUS_REQUESTED, Bed::STATUS_OCCUPIED))
        ->toThrow(IllegalBedTransition::class);
});

test('assertCanCancelReservation allows REQUESTED and HELD, rejects terminal statuses (ADR-149 D-B)', function () {
    $this->lifecycle->assertCanCancelReservation(BedReservation::STATUS_REQUESTED);
    $this->lifecycle->assertCanCancelReservation(BedReservation::STATUS_HELD);

    $invalidStatuses = [
        BedReservation::STATUS_FULFILLED,
        BedReservation::STATUS_CANCELLED,
        BedReservation::STATUS_EXPIRED,
    ];

    foreach ($invalidStatuses as $status) {
        expect(fn () => $this->lifecycle->assertCanCancelReservation($status))
            ->toThrow(IllegalBedReservationTransition::class);
    }
});
