<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class RecordLeftWithoutBeingSeenCommand implements AuthorizesAgainst
{
    public function __construct(
        public string $entryId,
        public ?string $reason = null,
    ) {}

    public function policyAbility(): string
    {
        return 'skip';
    }

    public function subjectId(): string
    {
        return $this->entryId;
    }
}
