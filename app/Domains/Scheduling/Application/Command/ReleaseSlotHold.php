<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class ReleaseSlotHold implements AuthorizesAgainst
{
    public function __construct(
        public string $slotId,
        public string $reason = 'Manual release',
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
