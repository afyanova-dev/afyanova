<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\WorkforceAvailabilitySummary;
use Carbon\CarbonImmutable;

/**
 * Read-side port for derived workforce availability (25-hr-and-workforce-domain.md §6, §7.10).
 *
 * Availability is strictly derived: Roster − Approved Leave − Actual Attendance.
 */
interface WorkforceAvailabilityReader
{
    /**
     * @return list<WorkforceAvailabilitySummary>
     */
    public function getAvailability(
        string $facilityId,
        ?CarbonImmutable $atTime = null,
        ?string $departmentId = null,
        ?string $unitId = null,
        ?string $cadreId = null,
    ): array;

    public function isStaffAvailable(string $staffId, CarbonImmutable $dateTime): bool;

    public function isStaffOnLeave(string $staffId, CarbonImmutable $date): bool;
}
