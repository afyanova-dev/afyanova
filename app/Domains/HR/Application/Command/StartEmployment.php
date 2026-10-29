<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;

final readonly class StartEmployment implements AuthorizesAgainst
{
    public CarbonImmutable $startDate;

    public function __construct(
        public string $employmentId,
        string|CarbonImmutable $startDate,
    ) {
        $this->startDate = is_string($startDate)
            ? CarbonImmutable::parse($startDate)
            : $startDate;
    }

    public function policyAbility(): string
    {
        return 'start';
    }

    public function subjectId(): string
    {
        return $this->employmentId;
    }
}
