<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\WardRepository;

final class UpdateWardHandler
{
    public function __construct(
        private readonly WardRepository $wards,
    ) {}

    public function __invoke(UpdateWard $command): void
    {
        $ward = $this->wards->getById($command->wardId);

        $ward->updateDetails(
            name: $command->name,
            wardType: strtoupper(trim($command->wardType)),
            capacity: max(0, $command->capacity),
        );

        $this->wards->save($ward);
    }
}
