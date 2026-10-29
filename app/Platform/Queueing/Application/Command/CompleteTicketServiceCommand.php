<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class CompleteTicketServiceCommand implements AuthorizesAgainst
{
    public function __construct(
        public string $entryId,
    ) {}

    public function policyAbility(): string
    {
        return 'service';
    }

    public function subjectId(): string
    {
        return $this->entryId;
    }
}
