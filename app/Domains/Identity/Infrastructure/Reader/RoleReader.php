<?php

declare(strict_types=1);

namespace App\Domains\Identity\Infrastructure\Reader;

use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Domains\Identity\Contracts\Dto\ProtectedRoleReference;
use App\Domains\Identity\Domain\Model\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RoleReader
{
    public function findByKey(string $key): ?Role
    {
        return Role::query()->where('key', $key)->first();
    }

    public function protectedAdministrativeRoleByKey(
        string $tenantId,
        string $roleKey,
        string $scopeType,
    ): ?ProtectedRoleReference {
        $role = Role::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $roleKey)
            ->where('is_protected', true)
            ->where('scope_type', $scopeType)
            ->where('status', 'ACTIVE')
            ->first();

        return $role === null
            ? null
            : new ProtectedRoleReference((string) $role->id, (string) $role->key, $role->scope_type->value);
    }

    public function protectedAdministrativeRoleById(
        string $tenantId,
        string $roleId,
        string $roleKey,
    ): ?ProtectedRoleReference {
        $role = Role::query()
            ->whereKey($roleId)
            ->where('tenant_id', $tenantId)
            ->where('key', $roleKey)
            ->where('is_protected', true)
            ->where('status', 'ACTIVE')
            ->first();

        return $role === null
            ? null
            : new ProtectedRoleReference((string) $role->id, (string) $role->key, $role->scope_type->value);
    }

    /**
     * @param  list<string>  $roleKeys
     * @return list<string>
     */
    public function unavailableFacilityRoleKeys(string $tenantId, array $roleKeys): array
    {
        if ($roleKeys === []) {
            return [];
        }

        return array_values(DB::table('idn_roles')
            ->where('tenant_id', $tenantId)
            ->whereIn('key', $roleKeys)
            ->where(static fn ($query) => $query
                ->where('status', '!=', 'ACTIVE')
                ->orWhere('scope_type', '!=', 'FACILITY'))
            ->pluck('key')
            ->map(static fn ($key): string => (string) $key)
            ->all());
    }

    /**
     * @param  list<string>  $facilityIds
     * @return list<array{id: string, facilityId: string, userName: string, userEmail: string}>
     */
    public function activeFacilityRoleAssignments(
        string $tenantId,
        string $roleKey,
        array $facilityIds,
    ): array {
        if ($facilityIds === []) {
            return [];
        }

        $roleId = DB::table('idn_roles')
            ->where('tenant_id', $tenantId)
            ->where('key', $roleKey)
            ->where('status', 'ACTIVE')
            ->value('id');
        if (! is_string($roleId)) {
            return [];
        }

        return array_values(DB::table('idn_access_assignments as assignment')
            ->join('users', function ($join) use ($tenantId): void {
                $join->on('users.id', '=', 'assignment.user_id')
                    ->where('users.tenant_id', '=', $tenantId);
            })
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.role_id', $roleId)
            ->where('assignment.status', 'ACTIVE')
            ->whereIn('assignment.facility_id', $facilityIds)
            ->orderBy('users.name')
            ->get([
                'assignment.id',
                'assignment.facility_id',
                'users.name as user_name',
                'users.email as user_email',
            ])
            ->map(static fn (object $assignment): array => [
                'id' => (string) $assignment->id,
                'facilityId' => (string) $assignment->facility_id,
                'userName' => (string) $assignment->user_name,
                'userEmail' => (string) $assignment->user_email,
            ])->all());
    }

    public function activeProtectedFacilityRoleId(string $tenantId, string $roleKey): ?string
    {
        $roleId = DB::table('idn_roles')
            ->where('tenant_id', $tenantId)
            ->where('key', $roleKey)
            ->where('is_protected', true)
            ->where('scope_type', 'FACILITY')
            ->where('status', 'ACTIVE')
            ->value('id');

        return is_string($roleId) ? $roleId : null;
    }

    /** @return list<string> */
    public function facilityRelatedAccountIds(string $tenantId, string $facilityId, string $organizationId): array
    {
        $assignmentIds = DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->where(fn ($query) => $query->where('facility_id', $facilityId)
                ->orWhere(fn ($organization) => $organization
                    ->whereNull('facility_id')
                    ->where('organization_id', $organizationId)))
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        $promotionIds = DB::table('idn_admin_role_promotion_requests')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->pluck('target_user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_unique([...$assignmentIds, ...$promotionIds]));
    }

    public function accountHasFacilityRelationship(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $userId,
    ): bool {
        return DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where(fn ($query) => $query->where('facility_id', $facilityId)
                ->orWhere(fn ($organization) => $organization
                    ->whereNull('facility_id')
                    ->where('organization_id', $organizationId)))
            ->exists()
            || DB::table('idn_admin_role_promotion_requests')
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('target_user_id', $userId)
                ->exists();
    }

    /**
     * @return list<array{id: string, role: string, roleName: string, scope: string, status: string, effectiveFrom: ?string, effectiveTo: ?string, isEffective: bool, isLifecycleSuspended: bool, canManageIndividually: bool}>
     */
    public function facilityAccountAssignments(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $userId,
    ): array {
        return array_values(DB::table('idn_access_assignments as assignment')
            ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.user_id', $userId)
            ->where(fn ($query) => $query->where('assignment.facility_id', $facilityId)
                ->orWhere(fn ($organization) => $organization
                    ->whereNull('assignment.facility_id')
                    ->where('assignment.organization_id', $organizationId)))
            ->orderBy('role.name')
            ->get([
                'role.key as role_key',
                'role.name as role_name',
                'assignment.id as assignment_id',
                'assignment.facility_id',
                'assignment.status',
                'assignment.effective_from',
                'assignment.effective_to',
                DB::raw("exists (
                    select 1
                    from idn_staff_access_suspension_assignments suspension_assignment
                    join idn_staff_access_suspensions suspension
                      on suspension.id = suspension_assignment.suspension_id
                     and suspension.tenant_id = suspension_assignment.tenant_id
                    join hr_staff_user_links staff_link
                      on staff_link.staff_id = suspension.staff_id
                     and staff_link.tenant_id = suspension.tenant_id
                     and staff_link.user_id = assignment.user_id
                    where suspension_assignment.tenant_id = assignment.tenant_id
                      and suspension_assignment.assignment_id = assignment.id
                      and suspension.status = 'ACTIVE'
                ) as is_lifecycle_suspended"),
            ])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->assignment_id,
                'role' => (string) $row->role_key,
                'roleName' => (string) $row->role_name,
                'scope' => $row->facility_id === null ? 'ORGANIZATION' : 'FACILITY',
                'status' => (string) $row->status,
                'effectiveFrom' => $row->effective_from === null ? null : (string) $row->effective_from,
                'effectiveTo' => $row->effective_to === null ? null : (string) $row->effective_to,
                'isEffective' => $row->status === 'ACTIVE'
                    && ($row->effective_from === null || CarbonImmutable::parse((string) $row->effective_from)->lte(now()))
                    && ($row->effective_to === null || CarbonImmutable::parse((string) $row->effective_to)->gte(now())),
                'isLifecycleSuspended' => (bool) $row->is_lifecycle_suspended,
                'canManageIndividually' => $row->facility_id !== null
                    && array_key_exists((string) $row->role_key, FacilityUserRoleCatalog::STAFF_ROLES),
            ])->all());
    }

    /**
     * @return list<array{action: string, role: string, actorName: string, reason: ?string, occurredAt: string}>
     */
    public function facilityAccountAccessHistory(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $userId,
    ): array {
        $assignments = DB::table('idn_access_assignments as assignment')
            ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.user_id', $userId)
            ->where(fn ($query) => $query->where('assignment.facility_id', $facilityId)
                ->orWhere(fn ($organization) => $organization
                    ->whereNull('assignment.facility_id')
                    ->where('assignment.organization_id', $organizationId)))
            ->get(['assignment.id', 'role.key as role_key']);
        $assignmentRoles = $assignments->mapWithKeys(static fn (object $assignment): array => [
            (string) $assignment->id => (string) $assignment->role_key,
        ])->all();

        $events = DB::table('shared_audit_log')
            ->where('tenant_id', $tenantId)
            ->where(fn ($scope) => $scope->where('facility_id', $facilityId)->orWhereNull('facility_id'))
            ->where('action', 'like', 'identity.%')
            ->where(function ($targets) use ($userId, $assignmentRoles): void {
                $targets->where(fn ($user) => $user
                    ->where('target_type', 'user')
                    ->where('target_id', $userId));
                if ($assignmentRoles !== []) {
                    $targets->orWhere(fn ($assignment) => $assignment
                        ->where('target_type', 'assignment')
                        ->whereIn('target_id', array_keys($assignmentRoles)));
                }
            })
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['actor_id', 'action', 'target_type', 'target_id', 'reason', 'metadata', 'created_at']);
        $actorIds = $events->pluck('actor_id')->filter()->unique()->map(static fn ($id): string => (string) $id)->all();
        $actorNames = $actorIds === [] ? [] : DB::table('users')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $actorIds)
            ->pluck('name', 'id')
            ->map(static fn ($name): string => (string) $name)
            ->all();
        $history = [];

        foreach ($events as $event) {
            $metadata = is_array($event->metadata)
                ? $event->metadata
                : json_decode((string) $event->metadata, true, flags: JSON_THROW_ON_ERROR);
            $roleKey = is_string($metadata['role'] ?? null) ? $metadata['role'] : null;
            $eventAssignmentIds = is_array($metadata['assignments'] ?? null)
                ? array_values(array_filter($metadata['assignments'], 'is_string'))
                : [];
            $targetAssignmentId = $event->target_type === 'assignment' ? (string) $event->target_id : null;
            if ($targetAssignmentId !== null) {
                $eventAssignmentIds[] = $targetAssignmentId;
            }
            if (is_string($metadata['assignment'] ?? null)) {
                $eventAssignmentIds[] = $metadata['assignment'];
            }

            if ($roleKey !== null) {
                $roles = [$roleKey];
            } else {
                $roles = array_values(array_unique(array_filter(array_map(
                    static fn (string $id): ?string => $assignmentRoles[$id] ?? null,
                    $eventAssignmentIds,
                ))));
            }
            if ($roles === []) {
                $roles = ['facility_access'];
            }

            foreach ($roles as $role) {
                $history[] = [
                    'action' => (string) $event->action,
                    'role' => $role,
                    'actorName' => $actorNames[(string) $event->actor_id] ?? 'System',
                    'reason' => $event->reason === null ? null : (string) $event->reason,
                    'occurredAt' => CarbonImmutable::parse((string) $event->created_at, 'UTC')->toIso8601String(),
                ];
            }
        }

        return $history;
    }

    /**
     * @return list<array{role: string, status: string, reason: string, requestedAt: string}>
     */
    public function facilityAccountPromotionRequests(string $tenantId, string $facilityId, string $userId): array
    {
        return array_values(DB::table('idn_admin_role_promotion_requests')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('target_user_id', $userId)
            ->orderByDesc('created_at')
            ->get(['role_key', 'status', 'reason', 'created_at'])
            ->map(static fn (object $row): array => [
                'role' => (string) $row->role_key,
                'status' => (string) $row->status,
                'reason' => (string) $row->reason,
                'requestedAt' => (string) $row->created_at,
            ])->all());
    }

    /** @return list<string> permission keys granted by the role and its ancestors */
    public function effectivePermissionKeys(Role $role): array
    {
        $keys = [];
        $current = $role;
        $guard = 0;

        while ($current !== null && $guard++ < 20) {
            foreach ($current->permissions as $permission) {
                $keys[(string) $permission->key] = true;
            }
            $current = $current->parent()->first();
        }

        return array_keys($keys);
    }
}
