<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Service\BedLifecycle;

final class BlockBedHandler
{
    public function __construct(
        private readonly BedRepository $beds,
        private readonly BedLifecycle $lifecycle,
    ) {}

    public function __invoke(BlockBed $command): void
    {
        $bed = $this->beds->getById($command->bedId);

        $this->lifecycle->assertCanBlock((string) $bed->status);

        $targetStatus = $command->isMaintenance ? Bed::STATUS_MAINTENANCE : Bed::STATUS_BLOCKED;

        $bed->transitionStatus($targetStatus);

        $this->beds->save($bed);
    }
}
