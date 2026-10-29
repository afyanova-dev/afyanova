<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;

final readonly class RebuildAttendanceSessions implements AuthorizesAgainst
{
    public CarbonImmutable $startDate;

    public CarbonImmutable $endDate;

    public function __construct(
        public string $staffId,
        string|CarbonImmutable $startDate,
        string|CarbonImmutable $endDate,
    ) {
        $this->startDate = is_string($startDate) ? CarbonImmutable::parse($startDate)->startOfDay() : $startDate->startOfDay();
        $this->endDate = is_string($endDate) ? CarbonImmutable::parse($endDate)->endOfDay() : $endDate->endOfDay();
    }

    public function policyAbility(): string
    {
        return 'rebuild';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
