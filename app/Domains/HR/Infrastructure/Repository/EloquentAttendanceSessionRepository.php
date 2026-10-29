<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\AttendanceSessionRepository;
use App\Domains\HR\Domain\Exception\AttendanceSessionNotFoundException;
use App\Domains\HR\Domain\Model\AttendanceSession;
use App\Domains\HR\Domain\ValueObject\AttendanceSessionStatus;
use App\Shared\Domain\EloquentRepository;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of AttendanceSessionRepository (25-hr-and-workforce-domain.md §6, §7.11).
 */
final class EloquentAttendanceSessionRepository extends EloquentRepository implements AttendanceSessionRepository
{
    protected function model(): string
    {
        return AttendanceSession::class;
    }

    public function getById(string $id): AttendanceSession
    {
        return AttendanceSession::query()->whereKey($id)->first()
            ?? throw AttendanceSessionNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): AttendanceSession
    {
        return AttendanceSession::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw AttendanceSessionNotFoundException::forId($id);
    }

    public function findOpenSessionForStaff(string $staffId): ?AttendanceSession
    {
        return AttendanceSession::query()
            ->where('staff_id', $staffId)
            ->where('status', AttendanceSessionStatus::OPEN->value)
            ->first();
    }

    public function findOpenSessionForStaffForUpdate(string $staffId): ?AttendanceSession
    {
        return AttendanceSession::query()
            ->where('staff_id', $staffId)
            ->where('status', AttendanceSessionStatus::OPEN->value)
            ->lockForUpdate()
            ->first();
    }

    public function deleteUnreconciledForStaffAndPeriod(string $staffId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return AttendanceSession::query()
            ->where('staff_id', $staffId)
            ->whereBetween('started_at', [$from, $to])
            ->where('status', '!=', AttendanceSessionStatus::RECONCILED->value)
            ->delete();
    }

    /**
     * @return list<AttendanceSession>
     */
    public function forStaffAndPeriod(string $staffId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(AttendanceSession::query()
            ->where('staff_id', $staffId)
            ->whereBetween('started_at', [$from, $to])
            ->orderBy('started_at', 'asc')
            ->get()
            ->all());
    }
}
