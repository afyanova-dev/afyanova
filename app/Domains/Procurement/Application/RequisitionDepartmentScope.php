<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application;

use App\Domains\Organization\Contracts\OrganizationApi;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;

/**
 * Department access for requisitions is derived from effective Identity
 * assignments. A null department is the explicit facility-wide scope.
 */
final class RequisitionDepartmentScope
{
    public function __construct(
        private readonly ScopeChecker $scope,
        private readonly OrganizationApi $organization,
    ) {}

    public function canAccessWorkspace(User $user, ?string $facilityId): bool
    {
        return $this->hasPermissionWithinFacility($user, $facilityId, 'procurement.access');
    }

    public function hasPermissionWithinFacility(
        User $user,
        ?string $facilityId,
        string $permission,
    ): bool {
        if ($this->hasPermission($user, $facilityId, null, $permission)) {
            return true;
        }
        if ($facilityId === null) {
            return false;
        }

        foreach ($this->activeDepartmentIds($facilityId) as $departmentId) {
            if ($this->hasPermission($user, $facilityId, $departmentId, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{id: string, name: string, code: string}>
     */
    public function departmentsForCreation(User $user, string $facilityId): array
    {
        $structure = $this->organization->getFacilityStructure($facilityId);
        if ($structure === null) {
            return [];
        }

        $facilityWide = $this->hasPermission(
            $user,
            $facilityId,
            null,
            'procurement.requisitions.create',
        );

        $departments = [];
        foreach ($structure->departments as $department) {
            $id = (string) ($department['id'] ?? '');
            if ($id === '' || ! $this->organization->departmentIsActive($facilityId, $id)) {
                continue;
            }

            if ($facilityWide || $this->hasPermission(
                $user,
                $facilityId,
                $id,
                'procurement.requisitions.create',
            )) {
                $departments[] = [
                    'id' => $id,
                    'name' => (string) ($department['name'] ?? ''),
                    'code' => (string) ($department['code'] ?? ''),
                ];
            }
        }

        return $departments;
    }

    public function canCreateFor(User $user, string $facilityId, ?string $departmentId): bool
    {
        if ($departmentId !== null && ! $this->organization->departmentIsActive($facilityId, $departmentId)) {
            return false;
        }

        return $this->hasPermission(
            $user,
            $facilityId,
            $departmentId,
            'procurement.requisitions.create',
        );
    }

    public function canView(User $user, string $facilityId, ?string $departmentId): bool
    {
        return $this->hasPermission($user, $facilityId, $departmentId, 'procurement.access');
    }

    public function canViewFacilityWide(User $user, string $facilityId): bool
    {
        return $this->canView($user, $facilityId, null);
    }

    /** @return list<string> */
    public function departmentsForViewing(User $user, string $facilityId): array
    {
        return array_values(array_filter(
            $this->activeDepartmentIds($facilityId),
            fn (string $departmentId): bool => $this->canView($user, $facilityId, $departmentId),
        ));
    }

    public function canCreateForAnyDepartment(User $user, string $facilityId): bool
    {
        return $this->hasPermission(
            $user,
            $facilityId,
            null,
            'procurement.requisitions.create',
        );
    }

    private function hasPermission(
        User $user,
        ?string $facilityId,
        ?string $departmentId,
        string $permission,
    ): bool {
        return in_array(
            $permission,
            $this->scope->effectivePermissions($user, $facilityId, $departmentId),
            true,
        );
    }

    /** @return list<string> */
    private function activeDepartmentIds(string $facilityId): array
    {
        $structure = $this->organization->getFacilityStructure($facilityId);
        if ($structure === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (array $department): string => (string) ($department['id'] ?? ''),
                $structure->departments,
            ),
            fn (string $id): bool => $id !== ''
                && $this->organization->departmentIsActive($facilityId, $id),
        ));
    }
}
