<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class RejectLeave implements AuthorizesAgainst
{
    public function __construct(
        public string $leaveRequestId,
        public string $reviewerId,
        public string $rejectionReason,
    ) {}

    public function policyAbility(): string
    {
        return 'reject';
    }

    public function subjectId(): string
    {
        return $this->leaveRequestId;
    }
}
