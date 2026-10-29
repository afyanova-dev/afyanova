<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\Service\BedLifecycle;

final class DecommissionBedHandler
{
    public function __construct(
        private readonly BedRepository $beds,
        private readonly BedLifecycle $lifecycle,
    ) {}

    public function __invoke(DecommissionBed $command): void
    {
        $bed = $this->beds->getById($command->bedId);

        $this->lifecycle->assertCanDecommission((string) $bed->status);

        $bed->decommission();

        $this->beds->save($bed);
    }
}
