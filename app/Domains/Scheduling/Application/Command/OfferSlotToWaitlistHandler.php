<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\Exception\SlotNotAvailableException;
use App\Domains\Scheduling\Domain\SlotRepository;
use App\Domains\Scheduling\Domain\WaitlistEntryRepository;
use Carbon\CarbonImmutable;

final readonly class OfferSlotToWaitlistHandler
{
    public function __construct(
        private WaitlistEntryRepository $repository,
        private SlotRepository $slotRepository,
    ) {}

    public function __invoke(OfferSlotToWaitlist $command): void
    {
        $entry = $this->repository->getByIdForUpdate($command->waitlistEntryId);
        $slot = $this->slotRepository->getByIdForUpdate($command->slotId);

        if (! $slot->isAvailable()) {
            throw SlotNotAvailableException::forSlot((string) $slot->id, $slot->status->value);
        }

        $offeredUntil = CarbonImmutable::parse($command->offeredUntil);
        $slot->hold('WAITLIST_OFFER', $offeredUntil);
        $entry->offerSlot($command->slotId, $offeredUntil);

        $this->slotRepository->save($slot);
        $this->repository->save($entry);
    }
}
