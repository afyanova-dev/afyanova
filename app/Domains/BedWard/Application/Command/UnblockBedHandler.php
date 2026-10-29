<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Service\BedLifecycle;

final class UnblockBedHandler
{
    public function __construct(
        private readonly BedRepository $beds,
        private readonly BedLifecycle $lifecycle,
    ) {}

    public function __invoke(UnblockBed $command): void
    {
        $bed = $this->beds->getById($command->bedId);

        $this->lifecycle->assertCanUnblock((string) $bed->status);

        $bed->transitionStatus(Bed::STATUS_AVAILABLE);

        $this->beds->save($bed);
    }
}
