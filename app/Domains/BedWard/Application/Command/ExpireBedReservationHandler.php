<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\BedReservationRepository;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedReservation;

final class ExpireBedReservationHandler
{
    public function __construct(
        private readonly BedReservationRepository $reservations,
        private readonly BedRepository $beds,
    ) {}

    public function __invoke(ExpireBedReservation $command): void
    {
        $reservation = $this->reservations->getById($command->reservationId);

        if ($reservation->status !== BedReservation::STATUS_REQUESTED
            && $reservation->status !== BedReservation::STATUS_HELD) {
            return; // already resolved (fulfilled/cancelled/expired) — nothing to do
        }

        $wasHeld = $reservation->status === BedReservation::STATUS_HELD;

        $reservation->expire();
        $this->reservations->save($reservation);

        if ($wasHeld) {
            $bed = $this->beds->getById((string) $reservation->bed_id);
            $bed->transitionStatus(Bed::STATUS_AVAILABLE);
            $this->beds->save($bed);
        }
    }
}
