<?php

declare(strict_types=1);

namespace App\Domains\Organization\Contracts;

use App\Domains\Organization\Contracts\Dto\FacilityStructure;
use App\Domains\Organization\Contracts\Dto\InitialTenantStructure;

/**
 * The ONLY Organization surface other modules may import (IC §2.1).
 * Includes initial tenant-structure provisioning for the platform onboarding
 * workflow; ordinary organization writes remain protected by their own Admin
 * commands and permissions.
 */
interface OrganizationApi
{
    public function facilityExists(string $facilityId): bool;

    /**
     * The facility's type code (`DISPENSARY`, `HEALTH_CENTRE`, `HOSPITAL`,
     * `SPECIALIZED_HOSPITAL` — `org_facility_types.code`), or null when the
     * facility is absent from the current tenant scope.
     *
     * A read-only classifier, not a policy: consumers decide what a type means
     * for them (e.g. Procurement's approval-limit defaults).
     */
    public function facilityType(string $facilityId): ?string;

    public function departmentExists(string $facilityId, string $departmentId): bool;

    public function departmentIsActive(string $facilityId, string $departmentId): bool;

    public function unitExists(string $facilityId, string $unitId): bool;

    public function roomExists(string $facilityId, string $roomId): bool;

    public function getFacilityStructure(string $facilityId): ?FacilityStructure;

    /**
     * @param  list<string>  $facilityIds
     * @return array<string, list<string>> facility ID => enabled capability codes
     */
    public function enabledCapabilitiesForFacilities(array $facilityIds): array;

    public function provisionInitialTenantStructure(InitialTenantStructure $structure): void;
}
