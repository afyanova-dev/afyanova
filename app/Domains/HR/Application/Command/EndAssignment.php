<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;

final readonly class EndAssignment implements AuthorizesAgainst
{
    public CarbonImmutable $endedDate;

    public function __construct(
        public string $assignmentId,
        string|CarbonImmutable $endedDate,
        public ?string $reason = null,
    ) {
        $this->endedDate = is_string($endedDate) ? CarbonImmutable::parse($endedDate) : $endedDate;
    }

    public function policyAbility(): string
    {
        return 'end';
    }

    public function subjectId(): string
    {
        return $this->assignmentId;
    }
}
