<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabSpecimenRepository;
use App\Domains\Laboratory\Domain\Service\LabSpecimenLifecycle;

final class ReceiveSpecimenHandler
{
    public function __construct(
        private readonly LabSpecimenRepository $specimens,
        private readonly LabSpecimenLifecycle $lifecycle,
    ) {}

    public function __invoke(ReceiveSpecimen $command): void
    {
        $specimen = $this->specimens->getById($command->specimenId);

        $this->lifecycle->assertCanReceive((string) $specimen->status);

        $specimen->receive($command->actorId);

        $this->specimens->save($specimen);
    }
}
