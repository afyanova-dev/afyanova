<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Dto;

final readonly class ScheduleSummary
{
    public function __construct(
        public string $id,
        public string $facilityId,
        public string $resourceId,
        public ?string $appointmentTypeId,
        public int $dayOfWeek,
        public string $startTime,
        public string $endTime,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public bool $isActive,
    ) {}
}
