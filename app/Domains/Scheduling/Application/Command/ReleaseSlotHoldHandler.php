<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\SlotRepository;

final class ReleaseSlotHoldHandler
{
    public function __construct(
        private readonly SlotRepository $slots,
    ) {}

    public function __invoke(ReleaseSlotHold $command): void
    {
        $slot = $this->slots->getByIdForUpdate($command->slotId);

        $slot->releaseHold($command->reason);

        $this->slots->save($slot);
    }
}
