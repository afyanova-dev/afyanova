<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\RosterEntry;
use App\Shared\Domain\Repository;
use Carbon\CarbonImmutable;

/**
 * Write-side repository port for RosterEntry aggregate root (25-hr-and-workforce-domain.md §7; ADR-206).
 */
interface RosterEntryRepository extends Repository
{
    public function getById(string $id): RosterEntry;

    public function getByIdForUpdate(string $id): RosterEntry;

    public function hasActiveSlot(string $staffId, CarbonImmutable $date, string $shiftPatternId, ?string $excludeEntryId = null): bool;

    /**
     * @param  list<string>  $ids
     * @return list<RosterEntry>
     */
    public function getManyByIdsForUpdate(array $ids): array;

    /**
     * @return list<RosterEntry>
     */
    public function getDraftsForFacilityAndPeriod(string $facilityId, CarbonImmutable $startDate, CarbonImmutable $endDate): array;

    public function findPublishedForStaffOnDate(string $staffId, CarbonImmutable $date): ?RosterEntry;
}
