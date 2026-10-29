<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain;

use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for BedReservation aggregate (ADR-149 D-A).
 */
interface BedReservationRepository extends Repository
{
    public function getById(string $id): BedReservation;

    public function getByIdForUpdate(string $id): BedReservation;

    /**
     * The bed's open (REQUESTED or HELD) reservation, if any — at most one can
     * exist at a time (bw_bed_reservations_one_open_per_bed).
     */
    public function getOpenForBed(string $bedId): ?BedReservation;
}
