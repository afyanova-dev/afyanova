<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Dto;

final readonly class ScheduleExceptionSummary
{
    public function __construct(
        public string $id,
        public string $facilityId,
        public string $resourceId,
        public string $exceptionDate,
        public ?string $startTime,
        public ?string $endTime,
        public bool $isAvailable,
        public string $reason,
    ) {}
}
