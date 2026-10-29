<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\RosterEntryRepository;

/**
 * Handles SwapShift command (25-hr-and-workforce-domain.md §8; ADR-206).
 */
final class SwapShiftHandler
{
    public function __construct(
        private readonly RosterEntryRepository $rosterEntries,
    ) {}

    public function __invoke(SwapShift $command): void
    {
        $entryA = $this->rosterEntries->getByIdForUpdate($command->entryAId);
        $entryB = $this->rosterEntries->getByIdForUpdate($command->entryBId);

        $entryA->swapWith($entryB);

        $this->rosterEntries->save($entryA);
        $this->rosterEntries->save($entryB);
    }
}
