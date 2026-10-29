<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabSpecimenRepository;
use App\Domains\Laboratory\Domain\Service\LabSpecimenLifecycle;

final class RequestRecollectionHandler
{
    public function __construct(
        private readonly LabSpecimenRepository $specimens,
        private readonly LabSpecimenLifecycle $lifecycle,
    ) {}

    public function __invoke(RequestRecollection $command): void
    {
        $specimen = $this->specimens->getById($command->specimenId);

        $this->lifecycle->assertCanRequestRecollection((string) $specimen->status);

        $specimen->requestRecollection();

        $this->specimens->save($specimen);
    }
}
