<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ShiftPatternRepository;

/**
 * Handles UpdateShiftPattern command (25-hr-and-workforce-domain.md §6; ADR-206).
 */
final class UpdateShiftPatternHandler
{
    public function __construct(
        private readonly ShiftPatternRepository $patterns,
    ) {}

    public function __invoke(UpdateShiftPattern $command): void
    {
        $pattern = $this->patterns->getByIdForUpdate($command->shiftPatternId);

        $pattern->updateDetails(
            name: $command->name,
            startTime: $command->startTime,
            endTime: $command->endTime,
            breakMinutes: $command->breakMinutes,
            isOvernight: $command->isOvernight,
            isActive: $command->isActive,
        );

        $this->patterns->save($pattern);
    }
}
