<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class DefineSchedule implements AuthorizesAgainst
{
    public string $scheduleId;

    public CarbonImmutable $effectiveFrom;

    public ?CarbonImmutable $effectiveTo;

    public function __construct(
        public string $facilityId,
        public string $resourceId,
        public int $dayOfWeek,
        public string $startTime,
        public string $endTime,
        string|CarbonImmutable $effectiveFrom,
        string|CarbonImmutable|null $effectiveTo = null,
        public ?string $appointmentTypeId = null,
        public bool $isActive = true,
        ?string $scheduleId = null,
    ) {
        $this->scheduleId = $scheduleId ?? (string) Str::uuid();
        $this->effectiveFrom = is_string($effectiveFrom) ? CarbonImmutable::parse($effectiveFrom) : $effectiveFrom;
        $this->effectiveTo = is_string($effectiveTo) ? CarbonImmutable::parse($effectiveTo) : $effectiveTo;
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
