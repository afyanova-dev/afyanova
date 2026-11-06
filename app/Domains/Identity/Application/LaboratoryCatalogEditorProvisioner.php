<?php

declare(strict_types=1);

namespace App\Domains\Identity\Application;

use App\Domains\Identity\Contracts\LaboratoryCatalogEditorRoleDefinition;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class LaboratoryCatalogEditorProvisioner
{
    public function provision(string $tenantId, string $actorId): string
    {
        foreach (LaboratoryCatalogEditorRoleDefinition::PERMISSIONS as $permission) {
            if (! PermissionRegistry::has($permission)) {
                throw new RuntimeException("Required permission '{$permission}' is not registered.");
            }
        }

        $role = DB::table('idn_roles')
            ->where('tenant_id', $tenantId)
            ->where('key', LaboratoryCatalogEditorRoleDefinition::KEY)
            ->lockForUpdate()
            ->first();

        $created = $role === null;

        if ($role === null) {
            $roleId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $roleId,
                'tenant_id' => $tenantId,
                'key' => LaboratoryCatalogEditorRoleDefinition::KEY,
                'name' => 'Laboratory Catalog Editor',
                'status' => 'ACTIVE',
                'scope_type' => 'ORG',
                'is_protected' => true,
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            if ($role->scope_type !== 'ORG'
                || $role->status !== 'ACTIVE'
                || $role->parent_role_id !== null) {
                throw new RuntimeException('The Laboratory Catalog Editor role differs from its approved definition.');
            }

            $roleId = (string) $role->id;
        }

        $existingPermissions = DB::table('idn_permission_role as pr')
            ->join('idn_permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('pr.role_id', $roleId)
            ->pluck('p.key')
            ->map(static fn ($key): string => (string) $key)
            ->all();

        if (array_diff($existingPermissions, LaboratoryCatalogEditorRoleDefinition::PERMISSIONS) !== []) {
            throw new RuntimeException('The Laboratory Catalog Editor role has unexpected permissions.');
        }

        $addedPermissions = array_values(array_diff(
            LaboratoryCatalogEditorRoleDefinition::PERMISSIONS,
            $existingPermissions,
        ));

        foreach ($addedPermissions as $permissionKey) {
            $permissionId = DB::table('idn_permissions')->where('key', $permissionKey)->value('id');

            if (! is_string($permissionId)) {
                $permissionId = (string) Str::uuid7();
                DB::table('idn_permissions')->insert([
                    'id' => $permissionId,
                    'key' => $permissionKey,
                    'layer' => 'ACTION',
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

        if ($created || $addedPermissions !== []) {
            app(AuditLogger::class)->recordFor(
                tenantId: $tenantId,
                action: $created
                    ? 'identity.laboratory_catalog_editor.role_provisioned'
                    : 'identity.laboratory_catalog_editor.role_reconciled',
                actorId: $actorId,
                metadata: [
                    'role' => LaboratoryCatalogEditorRoleDefinition::KEY,
                    'permissions_added' => $addedPermissions,
                ],
                targetType: 'role',
                targetId: $roleId,
            );
        }

        return $roleId;
    }
}
