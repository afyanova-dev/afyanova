<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class PlaceQueueEntryOnHoldCommand implements AuthorizesAgainst
{
    public function __construct(
        public string $entryId,
        public string $holdReason,
        public string $holdProvenance,
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
