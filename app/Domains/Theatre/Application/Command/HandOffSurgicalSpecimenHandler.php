<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Service\SurgicalSpecimenLifecycle;
use App\Domains\Theatre\Domain\SurgicalSpecimenRepository;

final class HandOffSurgicalSpecimenHandler
{
    public function __construct(
        private readonly SurgicalSpecimenRepository $specimens,
        private readonly SurgicalSpecimenLifecycle $lifecycle,
    ) {}

    public function __invoke(HandOffSurgicalSpecimen $command): void
    {
        $specimen = $this->specimens->getByIdForUpdate($command->specimenId);

        $this->lifecycle->assertCanHandOff((string) $specimen->status);

        $specimen->handOff(strtoupper(trim($command->destinationLaboratory)), $command->actorId);

        $this->specimens->save($specimen);
    }
}
