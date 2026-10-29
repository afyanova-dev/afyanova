<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedRepository;

final class UpdateBedHandler
{
    public function __construct(
        private readonly BedRepository $beds,
    ) {}

    public function __invoke(UpdateBed $command): void
    {
        $bed = $this->beds->getById($command->bedId);

        $bed->updateDetails(
            label: $command->label,
            bedClass: strtoupper(trim($command->bedClass)),
            features: $command->features,
        );

        $this->beds->save($bed);
    }
}
