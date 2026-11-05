<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedReservationRepository;
use App\Domains\BedWard\Domain\Exception\WardHasOpenReservations;
use App\Domains\BedWard\Domain\WardRepository;

final class DecommissionWardHandler
{
    public function __construct(
        private readonly WardRepository $wards,
        private readonly BedReservationRepository $reservations,
    ) {}

    public function __invoke(DecommissionWard $command): void
    {
        $ward = $this->wards->getByIdForUpdate($command->wardId);

        if ($this->reservations->hasOpenForWard($command->wardId)) {
            throw WardHasOpenReservations::forWard($command->wardId);
        }

        $ward->decommission();

        $this->wards->save($ward);
    }
}
