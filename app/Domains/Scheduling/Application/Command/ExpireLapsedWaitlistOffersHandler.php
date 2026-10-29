<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\SlotRepository;
use App\Domains\Scheduling\Domain\WaitlistEntryRepository;
use Carbon\CarbonImmutable;

final readonly class ExpireLapsedWaitlistOffersHandler
{
    public function __construct(
        private WaitlistEntryRepository $repository,
        private SlotRepository $slotRepository,
    ) {}

    public function __invoke(ExpireLapsedWaitlistOffers $command): int
    {
        $asOf = $command->asOf !== null
            ? CarbonImmutable::parse($command->asOf)
            : CarbonImmutable::now();

        $lapsedEntries = $this->repository->getLapsedOffers($asOf);
        $count = 0;

        foreach ($lapsedEntries as $entry) {
            if ($entry->offered_slot_id !== null) {
                $slot = $this->slotRepository->getByIdForUpdate((string) $entry->offered_slot_id);
                $slot->releaseHold();
                $this->slotRepository->save($slot);
            }

            $entry->expireOffer();
            $this->repository->save($entry);
            $count++;
        }

        return $count;
    }
}
