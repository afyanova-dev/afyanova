<?php

declare(strict_types=1);

namespace App\Domains\Organization\Application;

use App\Domains\Organization\Contracts\Dto\FacilityStructure;
use App\Domains\Organization\Contracts\Dto\InitialTenantStructure;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Organization\Infrastructure\Reader\CapabilityReader;
use App\Domains\Organization\Infrastructure\Reader\FacilityReader;
use App\Domains\Organization\Infrastructure\Reader\OrganizationStructureReader;
use App\Domains\Organization\Infrastructure\Writer\InitialTenantStructureWriter;

/**
 * Thin port implementation — delegates to readers only (IC §9: Application code
 * never touches Eloquent / the query builder directly).
 */
final class OrganizationApiService implements OrganizationApi
{
    public function __construct(
        private readonly FacilityReader $facilities,
        private readonly OrganizationStructureReader $structure,
        private readonly CapabilityReader $capabilities,
        private readonly InitialTenantStructureWriter $initialStructureWriter,
    ) {}

    public function facilityExists(string $facilityId): bool
    {
        return $this->facilities->exists($facilityId);
    }

    public function facilityType(string $facilityId): ?string
    {
        $facility = $this->facilities->find($facilityId);

        return $facility !== null ? (string) $facility->facility_type : null;
    }

    public function departmentExists(string $facilityId, string $departmentId): bool
    {
        return $this->structure->departmentExists($facilityId, $departmentId);
    }

    public function departmentIsActive(string $facilityId, string $departmentId): bool
    {
        return $this->structure->departmentIsActive($facilityId, $departmentId);
    }

    public function unitExists(string $facilityId, string $unitId): bool
    {
        return $this->structure->unitExists($facilityId, $unitId);
    }

    public function roomExists(string $facilityId, string $roomId): bool
    {
        return $this->structure->roomExists($facilityId, $roomId);
    }

    public function getFacilityStructure(string $facilityId): ?FacilityStructure
    {
        return $this->structure->forFacility($facilityId);
    }

    public function enabledCapabilitiesForFacilities(array $facilityIds): array
    {
        return $this->capabilities->enabledCodesForFacilities($facilityIds);
    }

    public function provisionInitialTenantStructure(InitialTenantStructure $structure): void
    {
        $this->initialStructureWriter->create($structure);
    }
}
