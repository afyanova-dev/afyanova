<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class RequestLeave implements AuthorizesAgainst
{
    public string $leaveRequestId;

    public CarbonImmutable $startDate;

    public CarbonImmutable $endDate;

    public function __construct(
        public string $facilityId,
        public string $staffId,
        public string $leaveTypeId,
        string|CarbonImmutable $startDate,
        string|CarbonImmutable $endDate,
        public float $days,
        public ?string $reason = null,
        ?string $leaveRequestId = null,
    ) {
        $this->leaveRequestId = $leaveRequestId ?? (string) Str::uuid();
        $this->startDate = is_string($startDate) ? CarbonImmutable::parse($startDate)->startOfDay() : $startDate->startOfDay();
        $this->endDate = is_string($endDate) ? CarbonImmutable::parse($endDate)->endOfDay() : $endDate->endOfDay();
    }

    public function policyAbility(): string
    {
        return 'request';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
