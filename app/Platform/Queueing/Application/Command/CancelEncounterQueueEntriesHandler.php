<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Platform\Queueing\Domain\Repository\QueueEntryRepository;

final class CancelEncounterQueueEntriesHandler
{
    public function __construct(
        private readonly QueueEntryRepository $queueEntryRepository,
    ) {}

    public function __invoke(CancelEncounterQueueEntriesCommand $command): int
    {
        $activeEntries = $this->queueEntryRepository->listActiveForEncounter($command->encounterId);

        $count = 0;
        foreach ($activeEntries as $entry) {
            $entry->cancel($command->reason);
            $this->queueEntryRepository->save($entry);
            $count++;
        }

        return $count;
    }
}
