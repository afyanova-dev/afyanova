<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\OnShiftStaffSummary;
use App\Domains\HR\Contracts\Dto\RosterEntrySummary;
use Carbon\CarbonImmutable;

/**
 * Read-side port for RosterEntry queries (25-hr-and-workforce-domain.md §7, §10, §13; ADR-206).
 */
interface RosterEntryReader
{
    public function summary(string $rosterEntryId): RosterEntrySummary;

    /**
     * @return list<RosterEntrySummary>
     */
    public function forFacilityAndPeriod(
        string $facilityId,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        ?string $departmentId = null,
        ?string $unitId = null
    ): array;

    /**
     * @return list<RosterEntrySummary>
     */
    public function forStaff(string $staffId, CarbonImmutable $startDate, CarbonImmutable $endDate): array;

    /**
     * @return list<OnShiftStaffSummary>
     */
    public function getOnShiftNow(string $facilityId, ?string $unitId = null, ?CarbonImmutable $atTime = null): array;

    /**
     * Number of future published roster entries for a staff member.
     * Used by TerminateStaffHandler to enforce ADR-206 Single-Aggregate Boundary Extension.
     */
    public function futurePublishedCount(string $staffId, ?CarbonImmutable $fromDate = null): int;
}
