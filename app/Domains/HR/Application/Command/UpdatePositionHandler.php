<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\CadreRepository;
use App\Domains\HR\Domain\PositionRepository;

/**
 * Handles UpdatePosition command (ADR-206).
 */
final class UpdatePositionHandler
{
    public function __construct(
        private readonly PositionRepository $positions,
        private readonly CadreRepository $cadres,
    ) {}

    public function __invoke(UpdatePosition $command): void
    {
        $position = $this->positions->getByIdForUpdate($command->positionId);

        // Assert cadre exists
        $this->cadres->getById($command->cadreId);

        $position->updateDetails(
            title: $command->title,
            cadreId: $command->cadreId,
            description: $command->description,
            isActive: $command->isActive,
        );

        $this->positions->save($position);
    }
}
