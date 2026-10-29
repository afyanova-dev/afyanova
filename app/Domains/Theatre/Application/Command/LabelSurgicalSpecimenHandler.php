<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Service\SurgicalSpecimenLifecycle;
use App\Domains\Theatre\Domain\SurgicalSpecimenRepository;

final class LabelSurgicalSpecimenHandler
{
    public function __construct(
        private readonly SurgicalSpecimenRepository $specimens,
        private readonly SurgicalSpecimenLifecycle $lifecycle,
    ) {}

    public function __invoke(LabelSurgicalSpecimen $command): void
    {
        $specimen = $this->specimens->getById($command->specimenId);

        $this->lifecycle->assertCanLabel((string) $specimen->status);

        $specimen->label();

        $this->specimens->save($specimen);
    }
}
