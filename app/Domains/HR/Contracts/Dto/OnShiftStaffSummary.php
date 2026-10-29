<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * On-shift staff member summary (25-hr-and-workforce-domain.md §13).
 */
final readonly class OnShiftStaffSummary
{
    public function __construct(
        public string $staffId,
        public string $staffNumber,
        public string $fullName,
        public ?string $cadreName,
        public ?string $positionTitle,
        public string $facilityId,
        public ?string $departmentId,
        public ?string $unitId,
        public string $shiftPatternId,
        public string $shiftCode,
        public string $startTime,
        public string $endTime,
        public bool $isOvernight,
        public string $date,
    ) {}
}
