<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class CancelLeave implements AuthorizesAgainst
{
    public function __construct(
        public string $leaveRequestId,
        public string $cancellationReason,
        public string $cancelledBy,
    ) {}

    public function policyAbility(): string
    {
        return 'cancel';
    }

    public function subjectId(): string
    {
        return $this->leaveRequestId;
    }
}
