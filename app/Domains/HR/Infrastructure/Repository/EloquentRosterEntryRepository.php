<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\Exception\RosterEntryNotFoundException;
use App\Domains\HR\Domain\Model\RosterEntry;
use App\Domains\HR\Domain\RosterEntryRepository;
use App\Domains\HR\Domain\ValueObject\RosterEntryStatus;
use App\Shared\Domain\EloquentRepository;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of RosterEntryRepository (25-hr-and-workforce-domain.md §7; ADR-206).
 */
final class EloquentRosterEntryRepository extends EloquentRepository implements RosterEntryRepository
{
    protected function model(): string
    {
        return RosterEntry::class;
    }

    public function getById(string $id): RosterEntry
    {
        return RosterEntry::query()->whereKey($id)->first()
            ?? throw RosterEntryNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): RosterEntry
    {
        return RosterEntry::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw RosterEntryNotFoundException::forId($id);
    }

    public function hasActiveSlot(
        string $staffId,
        CarbonImmutable $date,
        string $shiftPatternId,
        ?string $excludeEntryId = null,
    ): bool {
        $query = RosterEntry::query()
            ->where('staff_id', $staffId)
            ->where('date', $date->toDateString())
            ->where('shift_pattern_id', $shiftPatternId)
            ->whereNotIn('status', [RosterEntryStatus::CANCELLED->value, RosterEntryStatus::SWAPPED->value]);

        if ($excludeEntryId !== null) {
            $query->where('id', '!=', $excludeEntryId);
        }

        return $query->exists();
    }

    public function getManyByIdsForUpdate(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(RosterEntry::query()
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->all());
    }

    public function getDraftsForFacilityAndPeriod(
        string $facilityId,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        return array_values(RosterEntry::query()
            ->where('facility_id', $facilityId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where('status', RosterEntryStatus::DRAFT->value)
            ->lockForUpdate()
            ->get()
            ->all());
    }

    public function findPublishedForStaffOnDate(string $staffId, CarbonImmutable $date): ?RosterEntry
    {
        return RosterEntry::query()
            ->where('staff_id', $staffId)
            ->where('date', $date->toDateString())
            ->where('status', RosterEntryStatus::PUBLISHED->value)
            ->first();
    }
}
