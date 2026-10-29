<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Service;

use App\Domains\BedWard\Domain\Exception\IllegalBedReservationTransition;
use App\Domains\BedWard\Domain\Exception\IllegalBedTransition;
use App\Domains\BedWard\Domain\Exception\IllegalOccupancyTransition;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Domains\BedWard\Domain\Model\BedReservation;

/**
 * Bed, BedOccupancy and BedReservation lifecycle rules
 * (24-bed-and-ward-management-domain.md §8; ADR-147 D-C, ADR-148 D-D,
 * ADR-149 D-B/D-C). Pure domain logic, no I/O.
 */
final class BedLifecycle
{
    public function assertCanAssign(string $bedStatus): void
    {
        if ($bedStatus !== Bed::STATUS_AVAILABLE) {
            throw IllegalBedTransition::fromStatus('AssignBed', $bedStatus);
        }
    }

    public function assertCanRelease(string $occupancyStatus, string $bedStatus): void
    {
        if ($occupancyStatus !== BedOccupancy::STATUS_ACTIVE) {
            throw IllegalOccupancyTransition::forAction('ReleaseBed', $occupancyStatus);
        }

        if ($bedStatus !== Bed::STATUS_OCCUPIED) {
            throw IllegalBedTransition::fromStatus('ReleaseBed', $bedStatus);
        }
    }

    public function assertCanTransfer(string $occupancyStatus, string $fromBedStatus, string $toBedStatus): void
    {
        if ($occupancyStatus !== BedOccupancy::STATUS_ACTIVE) {
            throw IllegalOccupancyTransition::forAction('TransferBed', $occupancyStatus);
        }

        if ($fromBedStatus !== Bed::STATUS_OCCUPIED) {
            throw IllegalBedTransition::fromStatus('TransferBed (fromBed)', $fromBedStatus);
        }

        if ($toBedStatus !== Bed::STATUS_AVAILABLE) {
            throw IllegalBedTransition::fromStatus('TransferBed (toBed)', $toBedStatus);
        }
    }

    public function assertCanBlock(string $bedStatus): void
    {
        if ($bedStatus === Bed::STATUS_OCCUPIED) {
            throw IllegalBedTransition::cannotTransition($bedStatus, Bed::STATUS_BLOCKED);
        }

        if ($bedStatus !== Bed::STATUS_AVAILABLE && $bedStatus !== Bed::STATUS_CLEANING) {
            throw IllegalBedTransition::fromStatus('BlockBed', $bedStatus);
        }
    }

    public function assertCanUnblock(string $bedStatus): void
    {
        if ($bedStatus !== Bed::STATUS_BLOCKED && $bedStatus !== Bed::STATUS_MAINTENANCE) {
            throw IllegalBedTransition::fromStatus('UnblockBed', $bedStatus);
        }
    }

    public function assertCanMarkCleaning(string $bedStatus): void
    {
        if ($bedStatus === Bed::STATUS_CLEANING) {
            return; // no-op
        }

        // OCCUPIED is deliberately NOT permitted here: the only sanctioned
        // OCCUPIED -> CLEANING path is ReleaseBed, which ends the occupancy
        // first. Allowing the standalone MarkBedCleaning command to bypass that
        // would let bw_beds.status diverge from bw_bed_occupancies (an ACTIVE
        // occupancy left stranded on a bed that claims to be CLEANING/AVAILABLE).
        if ($bedStatus !== Bed::STATUS_AVAILABLE) {
            throw IllegalBedTransition::fromStatus('MarkBedCleaning', $bedStatus);
        }
    }

    public function assertCanMarkReady(string $bedStatus): void
    {
        if ($bedStatus !== Bed::STATUS_CLEANING) {
            throw IllegalBedTransition::fromStatus('MarkBedReady', $bedStatus);
        }
    }

    public function assertCanDecommission(string $bedStatus): void
    {
        if ($bedStatus === Bed::STATUS_OCCUPIED) {
            throw IllegalBedTransition::cannotTransition($bedStatus, Bed::STATUS_DECOMMISSIONED);
        }
    }

    // --- BedReservation (ADR-149 D-B/D-C) ---------------------------------

    public function assertCanHold(string $reservationStatus, string $bedStatus): void
    {
        if ($reservationStatus !== BedReservation::STATUS_REQUESTED) {
            throw IllegalBedReservationTransition::forAction('HoldBedReservation', $reservationStatus);
        }

        if ($bedStatus !== Bed::STATUS_AVAILABLE) {
            throw IllegalBedTransition::fromStatus('HoldBedReservation', $bedStatus);
        }
    }

    public function assertCanCancelReservation(string $reservationStatus): void
    {
        if ($reservationStatus !== BedReservation::STATUS_REQUESTED
            && $reservationStatus !== BedReservation::STATUS_HELD) {
            throw IllegalBedReservationTransition::forAction('CancelBedReservation', $reservationStatus);
        }
    }
}
