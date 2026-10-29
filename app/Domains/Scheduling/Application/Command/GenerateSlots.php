<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;

final readonly class GenerateSlots implements AuthorizesAgainst
{
    public CarbonImmutable $startDate;

    public CarbonImmutable $endDate;

    public function __construct(
        public string $facilityId,
        public string $resourceId,
        string|CarbonImmutable $startDate,
        string|CarbonImmutable $endDate,
        public ?string $appointmentTypeId = null,
        public ?int $slotDurationMinutes = null,
        public int $capacity = 1,
    ) {
        $this->startDate = is_string($startDate) ? CarbonImmutable::parse($startDate) : $startDate;
        $this->endDate = is_string($endDate) ? CarbonImmutable::parse($endDate) : $endDate;
    }

    public function policyAbility(): string
    {
        return 'generate';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
