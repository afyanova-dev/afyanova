<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\AttendanceEventRepository;
use App\Domains\HR\Domain\Exception\AttendanceEventNotFoundException;
use App\Domains\HR\Domain\Model\AttendanceEvent;
use App\Shared\Domain\EloquentRepository;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of AttendanceEventRepository (25-hr-and-workforce-domain.md §6, §7.11).
 */
final class EloquentAttendanceEventRepository extends EloquentRepository implements AttendanceEventRepository
{
    protected function model(): string
    {
        return AttendanceEvent::class;
    }

    public function getById(string $id): AttendanceEvent
    {
        return AttendanceEvent::query()->whereKey($id)->first()
            ?? throw AttendanceEventNotFoundException::forId($id);
    }

    public function existsByExternalEventId(string $externalEventId): bool
    {
        return AttendanceEvent::query()
            ->where('external_event_id', $externalEventId)
            ->exists();
    }

    /**
     * @return list<AttendanceEvent>
     */
    public function forStaffChronological(string $staffId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(AttendanceEvent::query()
            ->where('staff_id', $staffId)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at', 'asc')
            ->get()
            ->all());
    }
}
