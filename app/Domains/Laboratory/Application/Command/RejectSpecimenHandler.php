<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabSpecimenRepository;
use App\Domains\Laboratory\Domain\Service\LabSpecimenLifecycle;

final class RejectSpecimenHandler
{
    public function __construct(
        private readonly LabSpecimenRepository $specimens,
        private readonly LabSpecimenLifecycle $lifecycle,
    ) {}

    public function __invoke(RejectSpecimen $command): void
    {
        $specimen = $this->specimens->getById($command->specimenId);

        $this->lifecycle->assertCanReject((string) $specimen->status);

        $specimen->reject(strtoupper(trim($command->reasonCode)), $command->detail);

        $this->specimens->save($specimen);
    }
}
