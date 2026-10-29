<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\SlotRepository;
use App\Domains\Scheduling\Domain\WaitlistEntryRepository;

final readonly class CancelWaitlistEntryHandler
{
    public function __construct(
        private WaitlistEntryRepository $repository,
        private SlotRepository $slotRepository,
    ) {}

    public function __invoke(CancelWaitlistEntry $command): void
    {
        $entry = $this->repository->getByIdForUpdate($command->waitlistEntryId);

        if ($entry->offered_slot_id !== null) {
            $slot = $this->slotRepository->getByIdForUpdate((string) $entry->offered_slot_id);
            $slot->releaseHold();
            $this->slotRepository->save($slot);
        }

        $entry->cancel($command->reason);
        $this->repository->save($entry);
    }
}
