<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\AttendanceEvent;
use App\Shared\Domain\Repository;
use Carbon\CarbonImmutable;

/**
 * Write-side repository port for AttendanceEvent aggregate root (25-hr-and-workforce-domain.md §6, §7.11).
 */
interface AttendanceEventRepository extends Repository
{
    public function getById(string $id): AttendanceEvent;

    public function existsByExternalEventId(string $externalEventId): bool;

    /**
     * Retrieve events in strictly ascending chronological order for session reconstruction.
     *
     * @return list<AttendanceEvent>
     */
    public function forStaffChronological(string $staffId, CarbonImmutable $from, CarbonImmutable $to): array;
}
