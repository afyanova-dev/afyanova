<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class ReleaseQueueEntryHoldCommand implements AuthorizesAgainst
{
    public function __construct(
        public string $entryId,
        public string $clearanceProvenance,
    ) {}

    public function policyAbility(): string
    {
        return 'hold';
    }

    public function subjectId(): string
    {
        return $this->entryId;
    }
}
