<?php

declare(strict_types=1);

namespace App\Domains\Identity\Application;

use App\Domains\Identity\Contracts\BillingAdminRoleDefinition;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class BillingAdminRoleProvisioner
{
    public function provision(string $tenantId, string $actorId, string $facilityId): string
    {
        return DB::transaction(function () use ($tenantId, $actorId, $facilityId): string {
            foreach (BillingAdminRoleDefinition::PERMISSIONS as $permission) {
                if (! PermissionRegistry::has($permission)) {
                    throw new RuntimeException("Required permission '{$permission}' is not registered.");
                }
            }

            $role = DB::table('idn_roles')
                ->where('tenant_id', $tenantId)
                ->where('key', BillingAdminRoleDefinition::KEY)
                ->lockForUpdate()
                ->first();
            $created = $role === null;
            $protectionRepaired = false;

            if ($role === null) {
                $roleId = (string) Str::uuid7();
                DB::table('idn_roles')->insert([
                    'id' => $roleId,
                    'tenant_id' => $tenantId,
                    'key' => BillingAdminRoleDefinition::KEY,
                    'name' => 'Billing Administrator',
                    'status' => 'ACTIVE',
                    'scope_type' => 'FACILITY',
                    'is_protected' => true,
                    'version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $missingPermissions = BillingAdminRoleDefinition::PERMISSIONS;
            } else {
                if ($role->scope_type !== 'FACILITY'
                    || $role->status !== 'ACTIVE'
                    || $role->parent_role_id !== null) {
                    throw new RuntimeException('The existing Billing Administrator role differs from its approved definition.');
                }
                $existingPermissions = DB::table('idn_permission_role as pr')
                    ->join('idn_permissions as p', 'p.id', '=', 'pr.permission_id')
                    ->where('pr.role_id', $role->id)
                    ->pluck('p.key')
                    ->map(static fn ($key): string => (string) $key)
                    ->all();
                if (array_diff($existingPermissions, BillingAdminRoleDefinition::PERMISSIONS) !== []) {
                    throw new RuntimeException('The Billing Administrator role contains permissions outside its approved definition.');
                }
                $roleId = (string) $role->id;
                $missingPermissions = array_values(array_diff(
                    BillingAdminRoleDefinition::PERMISSIONS,
                    $existingPermissions,
                ));
                if (! $role->is_protected) {
                    DB::table('idn_roles')->where('id', $roleId)->update([
                        'is_protected' => true,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);
                    $protectionRepaired = true;
                }
            }

            foreach ($missingPermissions as $permissionKey) {
                $permissionId = DB::table('idn_permissions')->where('key', $permissionKey)->value('id');
                if (! is_string($permissionId)) {
                    $permissionId = (string) Str::uuid7();
                    DB::table('idn_permissions')->insert([
                        'id' => $permissionId,
                        'key' => $permissionKey,
                        'layer' => str_ends_with($permissionKey, '.access') ? 'ACCESS' : 'ACTION',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                DB::table('idn_permission_role')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($created || $protectionRepaired || $missingPermissions !== []) {
                app(AuditLogger::class)->recordFor(
                    tenantId: $tenantId,
                    action: $created
                        ? 'identity.billing_admin.role_provisioned'
                        : 'identity.billing_admin.role_definition_reconciled',
                    actorId: $actorId,
                    facilityId: $facilityId,
                    metadata: [
                        'role' => BillingAdminRoleDefinition::KEY,
                        'permissions_added' => $missingPermissions,
                        'protected_flag_repaired' => $protectionRepaired,
                    ],
                    targetType: 'role',
                    targetId: $roleId,
                );
            }

            return $roleId;
        });
    }
}
