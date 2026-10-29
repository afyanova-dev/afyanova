<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\WardRepository;

final class DecommissionWardHandler
{
    public function __construct(
        private readonly WardRepository $wards,
    ) {}

    public function __invoke(DecommissionWard $command): void
    {
        $ward = $this->wards->getById($command->wardId);

        $ward->decommission();

        $this->wards->save($ward);
    }
}
