<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class HoldSlot implements AuthorizesAgainst
{
    public function __construct(
        public string $slotId,
        public string $heldBy,
        public int $holdMinutes = 15,
    ) {}

    public function policyAbility(): string
    {
        return 'hold';
    }

    public function subjectId(): string
    {
        return $this->slotId;
    }
}
