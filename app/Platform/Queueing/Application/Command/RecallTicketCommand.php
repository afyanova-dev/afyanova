<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class RecallTicketCommand implements AuthorizesAgainst
{
    public function __construct(
        public string $entryId,
        public ?string $roomId = null,
        public ?string $staffId = null,
    ) {}

    public function policyAbility(): string
    {
        return 'call';
    }

    public function subjectId(): string
    {
        return $this->entryId;
    }
}
