<?php

declare(strict_types=1);

namespace App\Domains\Organization\Contracts;

interface OrganizationAdminApi
{
    /** @return list<array{id: string, organizationId: string}> */
    public function candidateFacilities(string $tenantId): array;

    /** @return list<array{id: string, name: string}> */
    public function activeOrganizations(string $tenantId): array;

    /**
     * @param  list<string>  $organizationIds
     * @return list<array{id: string, name: string}>
     */
    public function activeOrganizationsByIds(string $tenantId, array $organizationIds): array;

    /**
     * @param  list<string>  $facilityIds
     * @return list<array{id: string, organizationId: string, name: string}>
     */
    public function activeFacilities(string $tenantId, array $facilityIds): array;

    /**
     * @param  list<string>  $facilityIds
     * @return list<array<string, mixed>>
     */
    public function facilitySummaries(string $tenantId, array $facilityIds): array;

    /** @return array<string, mixed>|null */
    public function facilityPanel(string $tenantId, string $facilityId): ?array;

    /** @return list<array{code: string, name: string}> */
    public function facilityTypes(): array;

    /** @return list<array{code: string, name: string}> */
    public function careLevels(): array;

    /** @return list<array{code: string, name: string}> */
    public function ownershipTypes(): array;

    /** @return list<string> */
    public function activeOrganizationIds(string $tenantId): array;

    public function activeFacilityInOrganization(string $tenantId, string $facilityId): bool;

    /** @return array{id: string, name: string, organizationId: string}|null */
    public function activeFacilitySummary(string $tenantId, string $facilityId): ?array;

    public function activeDepartmentInFacility(string $tenantId, string $facilityId, string $departmentId): bool;

    public function activeUnitInFacility(string $tenantId, string $facilityId, string $unitId): bool;

    public function activeUnitInDepartment(
        string $tenantId,
        string $facilityId,
        string $departmentId,
        string $unitId,
    ): bool;

    /** @return list<array{id: string, name: string}> */
    public function activeDepartments(string $tenantId, string $facilityId): array;

    /** @return list<array{id: string, departmentId: string, name: string}> */
    public function activeUnits(string $tenantId, string $facilityId): array;

    public function capabilityIsRegistered(string $capabilityCode): bool;

    /** @return list<string> */
    public function enabledCapabilityCodes(string $facilityId): array;

    /** @return array<string, list<string>> */
    public function capabilityDependencyMap(): array;

    public function facilityName(string $tenantId, string $facilityId): ?string;

    public function createDepartment(string $tenantId, string $facilityId, string $name, string $code, ?string $kind, string $actorId): string;

    public function createUnit(string $tenantId, string $facilityId, string $departmentId, string $name, string $code, string $unitType, string $actorId): string;

    public function createFacility(string $tenantId, string $organizationId, string $name, string $code, string $facilityType, string $careLevel, string $ownershipType, string $actorId): string;

    public function createRoom(string $tenantId, string $facilityId, ?string $unitId, string $name, string $code, string $roomType, string $actorId): string;

    public function setFacilityCapabilityState(string $tenantId, string $facilityId, string $capabilityCode, string $state, ?string $reason, string $actorId): void;

    /** @param array<string, mixed> $changes */
    public function updateStructureRecord(string $tenantId, string $facilityId, string $kind, string $id, array $changes, string $actorId): void;

    public function setStructureRecordStatus(string $tenantId, string $facilityId, string $kind, string $id, string $status, string $actorId): void;
}
