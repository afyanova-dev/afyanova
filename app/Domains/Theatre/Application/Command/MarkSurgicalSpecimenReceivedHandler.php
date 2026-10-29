<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Service\SurgicalSpecimenLifecycle;
use App\Domains\Theatre\Domain\SurgicalSpecimenRepository;

final class MarkSurgicalSpecimenReceivedHandler
{
    public function __construct(
        private readonly SurgicalSpecimenRepository $specimens,
        private readonly SurgicalSpecimenLifecycle $lifecycle,
    ) {}

    public function __invoke(MarkSurgicalSpecimenReceived $command): void
    {
        $specimen = $this->specimens->getByIdForUpdate($command->specimenId);

        $this->lifecycle->assertCanMarkReceived((string) $specimen->status);

        $specimen->markReceived($command->labAccessionRef);

        $this->specimens->save($specimen);
    }
}
