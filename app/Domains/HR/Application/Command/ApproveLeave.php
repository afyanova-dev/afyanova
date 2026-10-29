<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class ApproveLeave implements AuthorizesAgainst
{
    public function __construct(
        public string $leaveRequestId,
        public string $reviewerId,
    ) {}

    public function policyAbility(): string
    {
        return 'approve';
    }

    public function subjectId(): string
    {
        return $this->leaveRequestId;
    }
}
