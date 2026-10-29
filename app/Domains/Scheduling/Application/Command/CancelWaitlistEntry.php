<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class CancelWaitlistEntry implements AuthorizesAgainst
{
    public function __construct(
        public string $waitlistEntryId,
        public ?string $reason = null,
    ) {}

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->waitlistEntryId;
    }
}
