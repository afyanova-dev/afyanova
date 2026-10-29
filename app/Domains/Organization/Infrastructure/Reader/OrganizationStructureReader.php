<?php

declare(strict_types=1);

namespace App\Domains\Organization\Infrastructure\Reader;

use App\Domains\Organization\Contracts\Dto\FacilityStructure;
use App\Domains\Organization\Domain\Model\Department;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Room;
use App\Domains\Organization\Domain\Model\Unit;

/**
 * Reads a facility's `Department → Unit → Room` tree
 * (docs/00-foundation/02-organization.md §15). RLS-scoped via the models'
 * global scope. Eloquent is permitted in `Infrastructure/` (IC §9).
 */
final class OrganizationStructureReader
{
    public function forFacility(string $facilityId): ?FacilityStructure
    {
        $facility = Facility::query()
            ->with(['departments.units.rooms'])
            ->find($facilityId);

        return $facility === null ? null : FacilityStructure::fromModel($facility);
    }

    public function departmentExists(string $facilityId, string $departmentId): bool
    {
        return Department::query()->where('facility_id', $facilityId)->whereKey($departmentId)->exists();
    }

    public function departmentIsActive(string $facilityId, string $departmentId): bool
    {
        return Department::query()
            ->where('facility_id', $facilityId)
            ->whereKey($departmentId)
            ->where('status', 'ACTIVE')
            ->exists();
    }

    public function unitExists(string $facilityId, string $unitId): bool
    {
        return Unit::query()->where('facility_id', $facilityId)->whereKey($unitId)->exists();
    }

    public function roomExists(string $facilityId, string $roomId): bool
    {
        return Room::query()->where('facility_id', $facilityId)->whereKey($roomId)->exists();
    }
}
