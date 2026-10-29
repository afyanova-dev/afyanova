<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;

final readonly class TerminateStaff implements AuthorizesAgainst
{
    public CarbonImmutable $terminatedDate;

    public function __construct(
        public string $staffId,
        string|CarbonImmutable $terminatedDate,
        public ?string $reason = null,
    ) {
        $this->terminatedDate = is_string($terminatedDate)
            ? CarbonImmutable::parse($terminatedDate)
            : $terminatedDate;
    }

    public function policyAbility(): string
    {
        return 'terminate';
    }

    public function subjectId(): string
    {
        return $this->staffId;
    }
}
