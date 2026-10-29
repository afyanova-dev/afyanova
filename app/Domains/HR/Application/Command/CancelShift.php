<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class CancelShift implements AuthorizesAgainst
{
    public function __construct(
        public string $rosterEntryId,
        public string $reason,
    ) {}

    public function policyAbility(): string
    {
        return 'update';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
