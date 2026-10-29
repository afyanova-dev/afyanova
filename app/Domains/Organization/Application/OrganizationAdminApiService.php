<?php

declare(strict_types=1);

namespace App\Domains\Organization\Application;

use App\Domains\Organization\Contracts\OrganizationAdminApi;
use App\Domains\Organization\Infrastructure\Reader\AdminOrganizationReader;
use App\Domains\Organization\Infrastructure\Reader\CapabilityReader;
use App\Domains\Organization\Infrastructure\Writer\AdminOrganizationWriter;

final class OrganizationAdminApiService implements OrganizationAdminApi
{
    public function __construct(
        private readonly AdminOrganizationReader $reader,
        private readonly CapabilityReader $capabilities,
        private readonly AdminOrganizationWriter $writer,
    ) {}

    public function candidateFacilities(string $tenantId): array
    {
        return $this->reader->candidateFacilities($tenantId);
    }

    public function activeOrganizations(string $tenantId): array
    {
        return $this->reader->activeOrganizations($tenantId);
    }

    public function activeOrganizationsByIds(string $tenantId, array $organizationIds): array
    {
        return $this->reader->activeOrganizationsByIds($tenantId, $organizationIds);
    }

    public function activeFacilities(string $tenantId, array $facilityIds): array
    {
        return $this->reader->activeFacilities($tenantId, $facilityIds);
    }

    public function facilitySummaries(string $tenantId, array $facilityIds): array
    {
        return $this->reader->facilitySummaries($tenantId, $facilityIds);
    }

    public function facilityPanel(string $tenantId, string $facilityId): ?array
    {
        return $this->reader->facilityPanel($tenantId, $facilityId);
    }

    public function facilityTypes(): array
    {
        return $this->reader->facilityTypes();
    }

    public function careLevels(): array
    {
        return $this->reader->careLevels();
    }

    public function ownershipTypes(): array
    {
        return $this->reader->ownershipTypes();
    }

    public function activeOrganizationIds(string $tenantId): array
    {
        return $this->reader->activeOrganizationIds($tenantId);
    }

    public function activeFacilityInOrganization(string $tenantId, string $facilityId): bool
    {
        return $this->reader->activeFacilityInOrganization($tenantId, $facilityId);
    }

    public function activeFacilitySummary(string $tenantId, string $facilityId): ?array
    {
        return $this->reader->activeFacilitySummary($tenantId, $facilityId);
    }

    public function activeDepartmentInFacility(string $tenantId, string $facilityId, string $departmentId): bool
    {
        return $this->reader->activeDepartmentInFacility($tenantId, $facilityId, $departmentId);
    }

    public function activeUnitInFacility(string $tenantId, string $facilityId, string $unitId): bool
    {
        return $this->reader->activeUnitInFacility($tenantId, $facilityId, $unitId);
    }

    public function activeUnitInDepartment(
        string $tenantId,
        string $facilityId,
        string $departmentId,
        string $unitId,
    ): bool {
        return $this->reader->activeUnitInDepartment($tenantId, $facilityId, $departmentId, $unitId);
    }

    public function activeDepartments(string $tenantId, string $facilityId): array
    {
        return $this->reader->activeDepartments($tenantId, $facilityId);
    }

    public function activeUnits(string $tenantId, string $facilityId): array
    {
        return $this->reader->activeUnits($tenantId, $facilityId);
    }

    public function capabilityIsRegistered(string $capabilityCode): bool
    {
        return $this->capabilities->isRegistered($capabilityCode);
    }

    public function enabledCapabilityCodes(string $facilityId): array
    {
        return $this->capabilities->enabledCodes($facilityId);
    }

    public function capabilityDependencyMap(): array
    {
        return $this->capabilities->dependencyMap();
    }

    public function facilityName(string $tenantId, string $facilityId): ?string
    {
        return $this->reader->facilityName($tenantId, $facilityId);
    }

    public function createDepartment(string $tenantId, string $facilityId, string $name, string $code, ?string $kind, string $actorId): string
    {
        return $this->writer->createDepartment($tenantId, $facilityId, $name, $code, $kind, $actorId);
    }

    public function createUnit(string $tenantId, string $facilityId, string $departmentId, string $name, string $code, string $unitType, string $actorId): string
    {
        return $this->writer->createUnit($tenantId, $facilityId, $departmentId, $name, $code, $unitType, $actorId);
    }

    public function createFacility(string $tenantId, string $organizationId, string $name, string $code, string $facilityType, string $careLevel, string $ownershipType, string $actorId): string
    {
        return $this->writer->createFacility($tenantId, $organizationId, $name, $code, $facilityType, $careLevel, $ownershipType, $actorId);
    }

    public function createRoom(string $tenantId, string $facilityId, ?string $unitId, string $name, string $code, string $roomType, string $actorId): string
    {
        return $this->writer->createRoom($tenantId, $facilityId, $unitId, $name, $code, $roomType, $actorId);
    }

    public function setFacilityCapabilityState(string $tenantId, string $facilityId, string $capabilityCode, string $state, ?string $reason, string $actorId): void
    {
        $this->writer->setFacilityCapabilityState($tenantId, $facilityId, $capabilityCode, $state, $reason, $actorId);
    }

    public function updateStructureRecord(string $tenantId, string $facilityId, string $kind, string $id, array $changes, string $actorId): void
    {
        $this->writer->updateStructureRecord($tenantId, $facilityId, $kind, $id, $changes, $actorId);
    }

    public function setStructureRecordStatus(string $tenantId, string $facilityId, string $kind, string $id, string $status, string $actorId): void
    {
        $this->writer->setStructureRecordStatus($tenantId, $facilityId, $kind, $id, $status, $actorId);
    }
}
