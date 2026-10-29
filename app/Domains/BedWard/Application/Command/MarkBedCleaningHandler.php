<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Service\BedLifecycle;

final class MarkBedCleaningHandler
{
    public function __construct(
        private readonly BedRepository $beds,
        private readonly BedLifecycle $lifecycle,
    ) {}

    public function __invoke(MarkBedCleaning $command): void
    {
        $bed = $this->beds->getById($command->bedId);

        $this->lifecycle->assertCanMarkCleaning((string) $bed->status);

        if ($bed->status !== Bed::STATUS_CLEANING) {
            $bed->transitionStatus(Bed::STATUS_CLEANING);
            $this->beds->save($bed);
        }
    }
}
