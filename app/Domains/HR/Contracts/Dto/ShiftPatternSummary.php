<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Shift pattern definition summary (25-hr-and-workforce-domain.md §6).
 */
final readonly class ShiftPatternSummary
{
    public function __construct(
        public string $shiftPatternId,
        public string $tenantId,
        public string $facilityId,
        public string $code,
        public string $name,
        public string $startTime,
        public string $endTime,
        public int $breakDurationMinutes,
        public bool $isOvernight,
        public ?string $colorCode,
        public bool $isActive,
        public int $version,
    ) {}
}
