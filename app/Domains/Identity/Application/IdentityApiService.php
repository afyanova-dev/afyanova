<?php

declare(strict_types=1);

namespace App\Domains\Identity\Application;

use App\Domains\Identity\Contracts\ClinicianRoleDefinition;
use App\Domains\Identity\Contracts\Dto\ProtectedRoleReference;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Identity\Contracts\LaboratoryCatalogEditorRoleDefinition;
use App\Domains\Identity\Domain\Model\Permission;
use App\Domains\Identity\Domain\Model\Role;
use App\Domains\Identity\Domain\Model\StaffIdentity;
use App\Domains\Identity\Infrastructure\Reader\RoleReader;
use App\Domains\Identity\Infrastructure\Reader\StaffIdentityReader;
use App\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IdentityApiService implements IdentityApi
{
    public function __construct(
        private readonly StaffIdentityReader $staff,
        private readonly RoleReader $roles,
        private readonly ScopeChecker $scope,
        private readonly FacilityStaffManagerRoleProvisioner $facilityStaffManagers,
        private readonly LinkedStaffGrantEligibility $grantEligibility,
        private readonly NurseRoleProvisioner $nurses,
        private readonly ClinicianRoleProvisioner $clinicians,
        private readonly PharmacistRoleProvisioner $pharmacists,
        private readonly LaboratoryTechnicianRoleProvisioner $laboratoryTechnicians,
        private readonly ReceptionistRoleProvisioner $receptionists,
        private readonly CashierRoleProvisioner $cashiers,
        private readonly BillingAdminRoleProvisioner $billingAdmins,
        private readonly TenantAdminRoleProvisioner $tenantAdmins,
        private readonly LaboratoryCatalogEditorProvisioner $laboratoryCatalogEditors,
    ) {}

    public function isStaff(string $userId): bool
    {
        return $this->staff->isStaff($userId);
    }

    public function linkStaffIdentity(
        string $tenantId,
        string $userId,
        string $staffRef,
        string $staffNumber,
    ): void {
        StaffIdentity::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'tenant_id' => $tenantId,
                'is_staff' => true,
                'staff_ref' => $staffRef,
                'staff_number' => $staffNumber,
            ],
        );
    }

    public function unlinkStaffIdentity(string $tenantId, string $userId): void
    {
        StaffIdentity::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->delete();
    }

    public function staffRefForUser(string $userId): ?string
    {
        return $this->staff->forUser($userId)?->staff_ref;
    }

    public function userExists(string $userId): bool
    {
        return User::query()->whereKey($userId)->exists();
    }

    public function effectivePermissions(string $userId, ?string $facilityId): array
    {
        $user = User::query()->findOrFail($userId);

        return $this->scope->effectivePermissions($user, $facilityId);
    }

    public function protectedAdministrativeRoleByKey(
        string $tenantId,
        string $roleKey,
        string $scopeType,
    ): ?ProtectedRoleReference {
        return $this->roles->protectedAdministrativeRoleByKey($tenantId, $roleKey, $scopeType);
    }

    public function protectedAdministrativeRoleById(
        string $tenantId,
        string $roleId,
        string $roleKey,
    ): ?ProtectedRoleReference {
        return $this->roles->protectedAdministrativeRoleById($tenantId, $roleId, $roleKey);
    }

    public function unavailableFacilityRoleKeys(string $tenantId, array $roleKeys): array
    {
        return $this->roles->unavailableFacilityRoleKeys($tenantId, $roleKeys);
    }

    public function activeFacilityRoleAssignments(
        string $tenantId,
        string $roleKey,
        array $facilityIds,
    ): array {
        return $this->roles->activeFacilityRoleAssignments($tenantId, $roleKey, $facilityIds);
    }

    public function assertEligibleForAccountSetup(string $tenantId, string $userId): void
    {
        $this->grantEligibility->lockEligibleTarget($tenantId, $userId);
    }

    public function activeProtectedFacilityRoleId(string $tenantId, string $roleKey): ?string
    {
        return $this->roles->activeProtectedFacilityRoleId($tenantId, $roleKey);
    }

    public function laboratoryCatalogEditorCandidates(string $tenantId): array
    {
        $technicians = DB::table('idn_access_assignments as aa')
            ->join('idn_roles as r', 'r.id', '=', 'aa.role_id')
            ->join('idn_staff_identities as si', 'si.user_id', '=', 'aa.user_id')
            ->join('users as u', 'u.id', '=', 'aa.user_id')
            ->where('aa.tenant_id', $tenantId)
            ->where('r.tenant_id', $tenantId)
            ->where('r.key', 'laboratory_technician')
            ->where('r.status', 'ACTIVE')
            ->where('aa.status', 'ACTIVE')
            ->whereNotNull('aa.facility_id')
            ->where('si.tenant_id', $tenantId)
            ->where('si.is_staff', true)
            ->where('u.tenant_id', $tenantId)
            ->where('u.status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('aa.effective_from')->orWhere('aa.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('aa.effective_to')->orWhere('aa.effective_to', '>=', now()))
            ->select(['u.id', 'u.name', 'u.email'])
            ->distinct()
            ->orderBy('u.name')
            ->orderBy('u.id')
            ->get();

        if ($technicians->isEmpty()) {
            return [];
        }

        $editorRoleId = DB::table('idn_roles')
            ->where('tenant_id', $tenantId)
            ->where('key', LaboratoryCatalogEditorRoleDefinition::KEY)
            ->where('status', 'ACTIVE')
            ->value('id');

        $editorIds = $editorRoleId === null
            ? []
            : DB::table('idn_access_assignments')
                ->where('tenant_id', $tenantId)
                ->where('role_id', $editorRoleId)
                ->whereNull('organization_id')
                ->whereNull('facility_id')
                ->whereNull('department_id')
                ->whereNull('unit_id')
                ->where('status', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
                ->pluck('user_id')
                ->map(static fn ($userId): string => (string) $userId)
                ->all();

        return array_values($technicians->map(static fn ($technician): array => [
            'userId' => (string) $technician->id,
            'name' => (string) $technician->name,
            'email' => (string) $technician->email,
            'isEditor' => in_array((string) $technician->id, $editorIds, true),
        ])->all());
    }

    public function grantLaboratoryCatalogEditor(string $tenantId, string $actorId, string $userId): void
    {
        DB::transaction(function () use ($tenantId, $actorId, $userId): void {
            $target = User::query()
                ->whereKey($userId)
                ->where('tenant_id', $tenantId)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();

            if ($target === null || ! $this->isActiveLaboratoryTechnician($tenantId, $userId)) {
                throw new \InvalidArgumentException('Catalog editor access can only be granted to an active laboratory technician.');
            }

            $roleId = $this->laboratoryCatalogEditors->provision($tenantId, $actorId);
            $alreadyAssigned = DB::table('idn_access_assignments')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('role_id', $roleId)
                ->whereNull('organization_id')
                ->whereNull('facility_id')
                ->whereNull('department_id')
                ->whereNull('unit_id')
                ->where('status', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
                ->exists();

            if ($alreadyAssigned) {
                return;
            }

            $assignmentId = (string) Str::uuid7();
            DB::table('idn_access_assignments')->insert([
                'id' => $assignmentId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => $roleId,
                'organization_id' => null,
                'facility_id' => null,
                'department_id' => null,
                'unit_id' => null,
                'status' => 'ACTIVE',
                'effective_from' => null,
                'effective_to' => null,
                'is_primary' => false,
                'granted_by' => $actorId,
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(AuditLogger::class)->recordFor(
                tenantId: $tenantId,
                action: 'identity.laboratory_catalog_editor.granted',
                actorId: $actorId,
                metadata: ['role' => LaboratoryCatalogEditorRoleDefinition::KEY],
                targetType: 'user',
                targetId: $userId,
            );
        });
    }

    public function revokeLaboratoryCatalogEditor(string $tenantId, string $actorId, string $userId): void
    {
        DB::transaction(function () use ($tenantId, $actorId, $userId): void {
            $roleId = DB::table('idn_roles')
                ->where('tenant_id', $tenantId)
                ->where('key', LaboratoryCatalogEditorRoleDefinition::KEY)
                ->value('id');

            if ($roleId === null) {
                return;
            }

            $assignments = DB::table('idn_access_assignments')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('role_id', $roleId)
                ->whereNull('organization_id')
                ->whereNull('facility_id')
                ->whereNull('department_id')
                ->whereNull('unit_id')
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->get(['id']);

            if ($assignments->isEmpty()) {
                return;
            }

            DB::table('idn_access_assignments')
                ->whereIn('id', $assignments->pluck('id'))
                ->update([
                    'status' => 'REVOKED',
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);

            app(AuditLogger::class)->recordFor(
                tenantId: $tenantId,
                action: 'identity.laboratory_catalog_editor.revoked',
                actorId: $actorId,
                metadata: ['role' => LaboratoryCatalogEditorRoleDefinition::KEY],
                targetType: 'user',
                targetId: $userId,
            );
        });
    }

    public function isActiveLaboratoryTechnician(string $tenantId, string $userId): bool
    {
        return DB::table('idn_access_assignments as aa')
            ->join('idn_roles as r', 'r.id', '=', 'aa.role_id')
            ->join('idn_staff_identities as si', 'si.user_id', '=', 'aa.user_id')
            ->where('aa.tenant_id', $tenantId)
            ->where('aa.user_id', $userId)
            ->where('r.tenant_id', $tenantId)
            ->where('r.key', 'laboratory_technician')
            ->where('r.status', 'ACTIVE')
            ->where('aa.status', 'ACTIVE')
            ->whereNotNull('aa.facility_id')
            ->where('si.tenant_id', $tenantId)
            ->where('si.is_staff', true)
            ->where(fn ($query) => $query->whereNull('aa.effective_from')->orWhere('aa.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('aa.effective_to')->orWhere('aa.effective_to', '>=', now()))
            ->exists();
    }

    public function isTenantAdmin(string $tenantId, string $userId): bool
    {
        return DB::table('idn_access_assignments as aa')
            ->join('idn_roles as r', 'r.id', '=', 'aa.role_id')
            ->join('org_organizations as o', 'o.id', '=', 'aa.organization_id')
            ->where('aa.tenant_id', $tenantId)
            ->where('aa.user_id', $userId)
            ->where('r.tenant_id', $tenantId)
            ->where('o.tenant_id', $tenantId)
            ->where('r.key', 'tenant_admin')
            ->where('r.scope_type', 'ORG')
            ->where('r.status', 'ACTIVE')
            ->where('aa.status', 'ACTIVE')
            ->whereNotNull('aa.organization_id')
            ->whereNull('aa.facility_id')
            ->whereNull('aa.department_id')
            ->whereNull('aa.unit_id')
            ->where(fn ($query) => $query->whereNull('aa.effective_from')->orWhere('aa.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('aa.effective_to')->orWhere('aa.effective_to', '>=', now()))
            ->exists();
    }

    public function roleCatalog(string $tenantId, int $page = 1, int $perPage = 50): array
    {
        $roles = Role::query()
            ->where('tenant_id', $tenantId)
            ->with(['permissions' => static function ($query): void {
                $query
                    ->select(['idn_permissions.id', 'idn_permissions.key', 'idn_permissions.layer'])
                    ->orderBy('idn_permissions.key');
            }])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', max(1, $page));

        return [
            'data' => array_values($roles->getCollection()->map(static fn (Role $role): array => [
                'id' => (string) $role->getKey(),
                'name' => (string) $role->name,
                'key' => (string) $role->key,
                'scope' => $role->scope_type->value,
                'status' => $role->status->value,
                'isProtected' => (bool) $role->is_protected,
                'permissions' => array_values($role->permissions
                    ->map(static fn (Permission $permission): array => [
                        'key' => (string) $permission->key,
                        'layer' => $permission->layer->value,
                    ])
                    ->all()),
            ])->all()),
            'total' => $roles->total(),
            'currentPage' => $roles->currentPage(),
            'lastPage' => $roles->lastPage(),
            'perPage' => $roles->perPage(),
        ];
    }

    public function provisionAccountSetupFacilityRole(
        string $tenantId,
        string $actorId,
        string $facilityId,
        string $roleKey,
    ): string {
        return $this->provisionFacilityRole($tenantId, $actorId, $facilityId, $roleKey);
    }

    public function provisionFacilityRole(
        string $tenantId,
        string $actorId,
        string $facilityId,
        string $roleKey,
    ): string {
        return match ($roleKey) {
            'nurse' => $this->nurses->provision($tenantId, $actorId, $facilityId),
            ClinicianRoleDefinition::KEY => $this->clinicians->provision($tenantId, $actorId, $facilityId),
            'pharmacist' => $this->pharmacists->provision($tenantId, $actorId, $facilityId),
            'laboratory_technician' => $this->laboratoryTechnicians->provision($tenantId, $actorId, $facilityId),
            'receptionist' => $this->receptionists->provision($tenantId, $actorId, $facilityId),
            'cashier' => $this->cashiers->provision($tenantId, $actorId, $facilityId),
            'billing_admin' => $this->billingAdmins->provision($tenantId, $actorId, $facilityId),
            default => throw new \RuntimeException("Unknown staff role '{$roleKey}'."),
        };
    }

    public function provisionAccountSetupTenantAdmin(string $tenantId, string $reason): string
    {
        return $this->tenantAdmins->provision($tenantId, null, $reason);
    }

    public function provisionTenantAdmin(string $tenantId, string $actorId, string $reason): string
    {
        return $this->tenantAdmins->provision($tenantId, $actorId, $reason);
    }

    public function provisionFacilityStaffManagerRole(string $tenantId, string $actorId, string $facilityId): string
    {
        return DB::transaction(fn (): string => $this->facilityStaffManagers->provision(
            $tenantId,
            $actorId,
            $facilityId,
        ));
    }

    public function facilityRelatedAccountIds(string $tenantId, string $facilityId, string $organizationId): array
    {
        return $this->roles->facilityRelatedAccountIds($tenantId, $facilityId, $organizationId);
    }

    public function accountHasFacilityRelationship(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $userId,
    ): bool {
        return $this->roles->accountHasFacilityRelationship($tenantId, $facilityId, $organizationId, $userId);
    }

    public function facilityAccountAssignments(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $userId,
    ): array {
        return $this->roles->facilityAccountAssignments($tenantId, $facilityId, $organizationId, $userId);
    }

    public function facilityAccountPromotionRequests(string $tenantId, string $facilityId, string $userId): array
    {
        return $this->roles->facilityAccountPromotionRequests($tenantId, $facilityId, $userId);
    }

    public function facilityAccountAccessHistory(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $userId,
    ): array {
        return $this->roles->facilityAccountAccessHistory($tenantId, $facilityId, $organizationId, $userId);
    }

    public function synchronizeRolePermissions(
        string $tenantId,
        array $rolePermissions,
        bool $dryRun,
        array $removeUnauthorizedPermissions = [],
    ): array {
        $missingRoles = [];
        $changes = [];

        foreach ($rolePermissions as $roleKey => $permissionKeys) {
            $role = Role::query()->where('key', $roleKey)->first();
            if ($role === null) {
                $missingRoles[] = $roleKey;

                continue;
            }

            foreach ($permissionKeys as $permissionKey) {
                if (! PermissionRegistry::has($permissionKey)
                    || $role->permissions()->where('idn_permissions.key', $permissionKey)->exists()) {
                    continue;
                }

                if (! $dryRun) {
                    $permission = Permission::query()->firstOrCreate(
                        ['key' => $permissionKey],
                        ['layer' => str_ends_with($permissionKey, '.access') ? 'ACCESS' : 'ACTION'],
                    );
                    $role->permissions()->syncWithoutDetaching([
                        $permission->id => [
                            'id' => (string) Str::uuid7(),
                            'tenant_id' => $tenantId,
                        ],
                    ]);
                }

                $changes[] = [
                    'roleKey' => $roleKey,
                    'permissionKey' => $permissionKey,
                    'operation' => 'attached',
                ];
            }

            foreach ($removeUnauthorizedPermissions as $permissionKey) {
                if (in_array($permissionKey, $permissionKeys, true)) {
                    continue;
                }

                $permission = Permission::query()->where('key', $permissionKey)->first();
                if ($permission === null || ! $role->permissions()->whereKey($permission->id)->exists()) {
                    continue;
                }

                if (! $dryRun) {
                    $role->permissions()->detach($permission->id);
                }

                $changes[] = [
                    'roleKey' => $roleKey,
                    'permissionKey' => $permissionKey,
                    'operation' => 'detached',
                ];
            }
        }

        return [
            'missingRoles' => $missingRoles,
            'changes' => $changes,
        ];
    }
}
