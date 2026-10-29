<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\BedReservationRepository;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Domains\BedWard\Domain\Service\BedLifecycle;

final class CancelBedReservationHandler
{
    public function __construct(
        private readonly BedReservationRepository $reservations,
        private readonly BedRepository $beds,
        private readonly BedLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelBedReservation $command): void
    {
        $reservation = $this->reservations->getById($command->reservationId);

        $this->lifecycle->assertCanCancelReservation((string) $reservation->status);

        $wasHeld = $reservation->status === BedReservation::STATUS_HELD;

        $reservation->cancel();
        $this->reservations->save($reservation);

        if ($wasHeld) {
            $bed = $this->beds->getById((string) $reservation->bed_id);
            $bed->transitionStatus(Bed::STATUS_AVAILABLE);
            $this->beds->save($bed);
        }
    }
}
