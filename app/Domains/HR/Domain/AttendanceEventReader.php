<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\AttendanceEventSummary;
use Carbon\CarbonImmutable;

/**
 * Read-side port for AttendanceEvent queries (25-hr-and-workforce-domain.md §6, §10).
 */
interface AttendanceEventReader
{
    public function summary(string $eventId): AttendanceEventSummary;

    /**
     * @return list<AttendanceEventSummary>
     */
    public function forStaff(
        string $staffId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?int $limit = null
    ): array;

    /**
     * @return list<AttendanceEventSummary>
     */
    public function forFacility(
        string $facilityId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?int $limit = null
    ): array;
}
