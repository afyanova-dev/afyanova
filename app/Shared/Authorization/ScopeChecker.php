<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Where may this user act, and what may they do there?"
 * (docs/00-foundation/03-identity-rbac.md §6, §11–§14, §17–§19, §28, §35–§38,
 * §66, §77–§79).
 *
 * Everything is evaluated against `idn_access_assignments` — never inferred from
 * the URL, a client-supplied id, or the user's merely-selected facility. An
 * assignment counts only when its own status is ACTIVE and it is within its
 * effective-date window, and its role's status is ACTIVE. Scope is a NULL-means-
 * wildcard match at each level (org / facility / department / unit). Role
 * hierarchy contributes the parent's *permissions* only — never its scope
 * (`03 §19`).
 *
 * Reads `org_facilities` via the query builder for `selectableFacilities` — a
 * table read, not a code dependency on `App\Domains\Organization`.
 */
final class ScopeChecker
{
    /**
     * The user's effective permission keys in the given scope context.
     *
     * @return list<string>
     */
    public function effectivePermissions(
        User $user,
        ?string $facilityId,
        ?string $departmentId = null,
        ?string $unitId = null,
        ?string $activeRoleId = null,
    ): array {
        $roleIds = $this->matchedRoleIds($user, $facilityId, $departmentId, $unitId);

        if ($activeRoleId !== null) {
            $roleIds = array_values(array_intersect($roleIds, [$activeRoleId]));
        }

        if ($roleIds === []) {
            return [];
        }

        $roleIds = $this->expandHierarchy($roleIds);

        return array_values(
            DB::table('idn_permission_role as pr')
                ->join('idn_permissions as p', 'p.id', '=', 'pr.permission_id')
                ->whereIn('pr.role_id', $roleIds)
                ->distinct()
                ->pluck('p.key')
                ->map(static fn ($k): string => (string) $k)
                ->all()
        );
    }

    /**
     * Whether a permission is effective for a facility-level operation.
     *
     * Workspace access keys may also be granted at a department scope within
     * the facility. Action keys remain limited to facility-wide grants here.
     */
    public function hasFacilityPermission(User $user, string $facilityId, string $permission): bool
    {
        $departmentIds = $this->facilityDepartmentScope($user, $facilityId, $permission);

        return $departmentIds === null || $departmentIds !== [];
    }

    /**
     * Department locations visible to a permission holder.
     *
     * `null` means the permission is held facility-wide; an array means the
     * permission is department-scoped and only those departments are in scope.
     *
     * @return list<string>|null
     */
    public function facilityDepartmentScope(User $user, string $facilityId, string $permission): ?array
    {
        if (in_array($permission, $this->effectivePermissions($user, $facilityId), true)) {
            return null;
        }

        if (! str_ends_with($permission, '.access')) {
            return [];
        }

        $departmentIds = DB::table('org_departments')
            ->where('facility_id', $facilityId)
            ->where('status', 'ACTIVE')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return array_values(array_filter(
            $departmentIds,
            fn (string $departmentId): bool => in_array(
                $permission,
                $this->effectivePermissions($user, $facilityId, $departmentId),
                true,
            ),
        ));
    }

    public function hasFacilityLocationPermission(
        User $user,
        string $facilityId,
        string $permission,
        ?string $locationDepartmentId,
    ): bool {
        $departmentIds = $this->facilityDepartmentScope($user, $facilityId, $permission);

        return $departmentIds === null
            || ($locationDepartmentId !== null && in_array($locationDepartmentId, $departmentIds, true));
    }

    /**
     * The effective permissions granted specifically by organization-scoped
     * assignments, excluding facility and lower-scope assignments.
     *
     * @return list<string>
     */
    public function effectiveOrganizationPermissions(User $user, string $organizationId): array
    {
        $roleIds = DB::table('idn_access_assignments as aa')
            ->join('idn_roles as r', 'r.id', '=', 'aa.role_id')
            ->where('aa.user_id', $user->getKey())
            ->where('aa.organization_id', $organizationId)
            ->whereNull('aa.facility_id')
            ->whereNull('aa.department_id')
            ->whereNull('aa.unit_id')
            ->where('aa.status', 'ACTIVE')
            ->where('r.status', 'ACTIVE')
            ->where('r.scope_type', 'ORG')
            ->where(fn ($q) => $q->whereNull('aa.effective_from')->orWhere('aa.effective_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('aa.effective_to')->orWhere('aa.effective_to', '>=', now()))
            ->distinct()
            ->pluck('aa.role_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if ($roleIds === []) {
            return [];
        }

        $roleIds = $this->expandHierarchy(array_values($roleIds));

        return array_values(
            DB::table('idn_permission_role as pr')
                ->join('idn_permissions as p', 'p.id', '=', 'pr.permission_id')
                ->whereIn('pr.role_id', $roleIds)
                ->distinct()
                ->pluck('p.key')
                ->map(static fn ($key): string => (string) $key)
                ->all()
        );
    }

    /**
     * The role keys from the user's effective assignments in the given scope context.
     *
     * @return list<string>
     */
    public function effectiveRoles(
        User $user,
        ?string $facilityId,
        ?string $departmentId = null,
        ?string $unitId = null,
    ): array {
        $roleIds = $this->matchedRoleIds($user, $facilityId, $departmentId, $unitId);

        if ($roleIds === []) {
            return [];
        }

        return array_values(
            DB::table('idn_roles')
                ->whereIn('id', $roleIds)
                ->pluck('key')
                ->map(static fn ($k): string => (string) $k)
                ->all()
        );
    }

    /**
     * Is the user assigned within a scope that covers this facility?
     * (A policy uses this to check a target's facility against the actor.)
     */
    public function withinFacilityScope(User $user, string $facilityId): bool
    {
        return $this->matchedRoleIds($user, $facilityId) !== [];
    }

    public function canUseGeneralContext(User $user): bool
    {
        return $this->effectivePermissions($user, null) !== []
            || app(OrganizationPermissionScope::class)->permissions($user) !== [];
    }

    /**
     * Facilities the user may set as their active context: the explicit
     * facility-scoped assignments, plus every active facility reachable through
     * an org-wide or tenant-wide assignment.
     *
     * @return list<string>
     */
    public function selectableFacilities(User $user): array
    {
        $rows = DB::table('idn_access_assignments')
            ->where('user_id', $user->getKey())
            ->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->get(['facility_id', 'organization_id']);

        $explicit = [];
        $orgWide = false;
        $orgIds = [];

        foreach ($rows as $row) {
            if ($row->facility_id !== null) {
                $explicit[(string) $row->facility_id] = true;
            } elseif ($row->organization_id !== null) {
                $orgIds[(string) $row->organization_id] = true;
            } else {
                $orgWide = true;
            }
        }

        $reachable = DB::table('org_facilities')->where('status', 'ACTIVE');
        if (! $orgWide) {
            if ($explicit === [] && $orgIds === []) {
                return [];
            }
            $reachable->where(function ($q) use ($explicit, $orgIds): void {
                $q->whereIn('id', array_keys($explicit) ?: ['00000000-0000-0000-0000-000000000000']);
                if ($orgIds !== []) {
                    $q->orWhereIn('organization_id', array_keys($orgIds));
                }
            });
        }

        return array_values($reachable->pluck('id')->map(static fn ($id): string => (string) $id)->all());
    }

    /**
     * The role ids from the user's effective assignments whose scope matches the
     * requested context.
     *
     * @return list<string>
     */
    private function matchedRoleIds(User $user, ?string $facilityId, ?string $departmentId = null, ?string $unitId = null): array
    {
        $query = DB::table('idn_access_assignments as aa')
            ->join('idn_roles as r', 'r.id', '=', 'aa.role_id')
            ->where('aa.user_id', $user->getKey())
            ->where('aa.status', 'ACTIVE')
            ->where('r.status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('aa.effective_from')->orWhere('aa.effective_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('aa.effective_to')->orWhere('aa.effective_to', '>=', now()));

        // organization level — an org-scoped assignment reaches only the
        // facilities of that organization (`03 §12`). With no facility context
        // only a tenant-wide (organization_id NULL) assignment matches.
        $organizationId = $facilityId === null
            ? null
            : DB::table('org_facilities')->where('id', $facilityId)->value('organization_id');
        $query->where(fn ($q) => $q->whereNull('aa.organization_id')->orWhere('aa.organization_id', $organizationId));

        // facility level
        if ($facilityId === null) {
            $query->whereNull('aa.facility_id');
        } else {
            $query->where(fn ($q) => $q->whereNull('aa.facility_id')->orWhere('aa.facility_id', $facilityId));
        }

        // department level — NULL on the assignment is a wildcard
        $query->where(fn ($q) => $q->whereNull('aa.department_id')->orWhere('aa.department_id', $departmentId));

        // unit level
        $query->where(fn ($q) => $q->whereNull('aa.unit_id')->orWhere('aa.unit_id', $unitId));

        return array_values($query->distinct()->pluck('aa.role_id')->map(static fn ($id): string => (string) $id)->all());
    }

    /**
     * Add each role's ancestors (`parent_role_id` chain) — permission inheritance
     * only (`03 §19`).
     *
     * @param  list<string>  $roleIds
     * @return list<string>
     */
    private function expandHierarchy(array $roleIds): array
    {
        $seen = array_fill_keys($roleIds, true);
        $frontier = $roleIds;

        while ($frontier !== []) {
            $parents = DB::table('idn_roles')
                ->whereIn('id', $frontier)
                ->whereNotNull('parent_role_id')
                ->pluck('parent_role_id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            $frontier = [];
            foreach ($parents as $parent) {
                if (! isset($seen[$parent])) {
                    $seen[$parent] = true;
                    $frontier[] = $parent;
                }
            }
        }

        return array_keys($seen);
    }
}
