<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\SlotRepository;

final readonly class ExpireSlotHoldHandler
{
    public function __construct(
        private SlotRepository $slots,
    ) {}

    public function __invoke(ExpireSlotHold $command): void
    {
        $slot = $this->slots->getByIdForUpdate($command->slotId);

        if ($slot->isHeld() && $slot->isHoldExpired()) {
            $slot->releaseHold('Expired hold sweep');
            $this->slots->save($slot);
        }
    }
}
