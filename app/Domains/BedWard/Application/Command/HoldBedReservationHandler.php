<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\BedReservationRepository;
use App\Domains\BedWard\Domain\Exception\IllegalBedReservationTransition;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Service\BedLifecycle;

final class HoldBedReservationHandler
{
    public function __construct(
        private readonly BedReservationRepository $reservations,
        private readonly BedRepository $beds,
        private readonly BedLifecycle $lifecycle,
    ) {}

    public function __invoke(HoldBedReservation $command): void
    {
        $reservation = $this->reservations->getById($command->reservationId);

        // A REQUESTED reservation whose hold_until has already lapsed cannot
        // be held (ADR-149 D-C). Not persisted here: the CommandBus wraps
        // this dispatch in one transaction, and it is about to roll back on
        // the exception below, so any save() in this branch would never
        // commit. The correction persists the next time a command that
        // SUCCEEDS touches this reservation or bed (e.g. AssignBed's own
        // self-heal, or the deferred hygiene job).
        if ($reservation->isExpired()) {
            throw IllegalBedReservationTransition::expired($command->reservationId);
        }

        $bed = $this->beds->getById((string) $reservation->bed_id);

        $this->lifecycle->assertCanHold((string) $reservation->status, (string) $bed->status);

        $reservation->hold();
        $bed->transitionStatus(Bed::STATUS_RESERVED);

        $this->reservations->save($reservation);
        $this->beds->save($bed);
    }
}
