<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class ConfirmLeaveTaken implements AuthorizesAgainst
{
    public function __construct(
        public string $leaveRequestId,
    ) {}

    public function policyAbility(): string
    {
        return 'confirm';
    }

    public function subjectId(): string
    {
        return $this->leaveRequestId;
    }
}
