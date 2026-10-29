<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;

final readonly class EndEmployment implements AuthorizesAgainst
{
    public CarbonImmutable $endDate;

    public function __construct(
        public string $employmentId,
        string|CarbonImmutable $endDate,
        public ?string $reason = null,
    ) {
        $this->endDate = is_string($endDate)
            ? CarbonImmutable::parse($endDate)
            : $endDate;
    }

    public function policyAbility(): string
    {
        return 'end';
    }

    public function subjectId(): string
    {
        return $this->employmentId;
    }
}
