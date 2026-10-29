<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class SwapShift implements AuthorizesAgainst
{
    public function __construct(
        public string $entryAId,
        public string $entryBId,
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
