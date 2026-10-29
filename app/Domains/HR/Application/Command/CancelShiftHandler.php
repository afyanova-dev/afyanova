<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\RosterEntryRepository;

/**
 * Handles CancelShift command (25-hr-and-workforce-domain.md §7; ADR-206).
 */
final class CancelShiftHandler
{
    public function __construct(
        private readonly RosterEntryRepository $rosterEntries,
    ) {}

    public function __invoke(CancelShift $command): void
    {
        $entry = $this->rosterEntries->getByIdForUpdate($command->rosterEntryId);

        $entry->cancel($command->reason);

        $this->rosterEntries->save($entry);
    }
}
