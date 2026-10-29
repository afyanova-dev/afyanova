<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Employment contract summary (25-hr-and-workforce-domain.md §3; ADR-206).
 */
final readonly class EmploymentSummary
{
    public function __construct(
        public string $employmentId,
        public string $tenantId,
        public string $staffId,
        public string $positionId,
        public ?string $positionTitle,
        public string $contractType,
        public string $status,
        public string $fte,
        public string $startDate,
        public ?string $endDate,
        public ?string $probationEndDate,
        public int $noticePeriodDays,
        public ?string $termsSummary,
        public ?string $suspensionReason,
        public ?string $endReason,
        public int $version,
    ) {}
}
