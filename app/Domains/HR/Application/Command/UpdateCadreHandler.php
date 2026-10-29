<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\CadreRepository;

/**
 * Handles UpdateCadre command (ADR-206).
 */
final class UpdateCadreHandler
{
    public function __construct(
        private readonly CadreRepository $cadres,
    ) {}

    public function __invoke(UpdateCadre $command): void
    {
        $cadre = $this->cadres->getByIdForUpdate($command->cadreId);

        $cadre->updateDetails(
            name: $command->name,
            category: $command->category,
            description: $command->description,
            isActive: $command->isActive,
        );

        $this->cadres->save($cadre);
    }
}
