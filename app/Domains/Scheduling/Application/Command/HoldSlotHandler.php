<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\SlotRepository;
use Carbon\CarbonImmutable;

final class HoldSlotHandler
{
    public function __construct(
        private readonly SlotRepository $slots,
    ) {}

    public function __invoke(HoldSlot $command): void
    {
        $slot = $this->slots->getByIdForUpdate($command->slotId);

        $heldUntil = CarbonImmutable::now()->addMinutes($command->holdMinutes);
        $slot->hold($command->heldBy, $heldUntil);

        $this->slots->save($slot);
    }
}
