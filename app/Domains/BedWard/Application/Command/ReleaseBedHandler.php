<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedOccupancyRepository;
use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Service\BedLifecycle;

final class ReleaseBedHandler
{
    public function __construct(
        private readonly BedOccupancyRepository $occupancies,
        private readonly BedRepository $beds,
        private readonly BedLifecycle $lifecycle,
    ) {}

    public function __invoke(ReleaseBed $command): void
    {
        $occupancy = $this->occupancies->getById($command->occupancyId);
        $bed = $this->beds->getById((string) $occupancy->bed_id);

        $this->lifecycle->assertCanRelease((string) $occupancy->status, (string) $bed->status);

        $occupancy->release($command->endReason, $command->endedAt);

        $bed->transitionStatus(Bed::STATUS_CLEANING);

        $this->occupancies->save($occupancy);
        $this->beds->save($bed);
    }
}
