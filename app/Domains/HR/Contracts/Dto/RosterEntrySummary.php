<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Roster entry summary (25-hr-and-workforce-domain.md §7).
 */
final readonly class RosterEntrySummary
{
    public function __construct(
        public string $rosterEntryId,
        public string $tenantId,
        public string $facilityId,
        public ?string $departmentId,
        public ?string $unitId,
        public string $staffId,
        public ?string $staffNumber,
        public ?string $staffName,
        public string $shiftPatternId,
        public ?string $shiftCode,
        public ?string $shiftName,
        public string $date,
        public string $startTime,
        public string $endTime,
        public string $status,
        public ?string $cancellationReason,
        public ?string $notes,
        public int $version,
    ) {}
}
