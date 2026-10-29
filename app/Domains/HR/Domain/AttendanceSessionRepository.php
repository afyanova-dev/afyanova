<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\AttendanceSession;
use App\Shared\Domain\Repository;
use Carbon\CarbonImmutable;

/**
 * Write-side repository port for AttendanceSession aggregate root (25-hr-and-workforce-domain.md §6, §7.11).
 */
interface AttendanceSessionRepository extends Repository
{
    public function getById(string $id): AttendanceSession;

    public function getByIdForUpdate(string $id): AttendanceSession;

    public function findOpenSessionForStaff(string $staffId): ?AttendanceSession;

    public function findOpenSessionForStaffForUpdate(string $staffId): ?AttendanceSession;

    /**
     * Delete sessions for staff and period during rebuild (only unreconciled sessions).
     */
    public function deleteUnreconciledForStaffAndPeriod(string $staffId, CarbonImmutable $from, CarbonImmutable $to): int;

    /**
     * @return list<AttendanceSession>
     */
    public function forStaffAndPeriod(string $staffId, CarbonImmutable $from, CarbonImmutable $to): array;
}
