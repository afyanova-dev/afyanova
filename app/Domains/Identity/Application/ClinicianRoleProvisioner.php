<?php

declare(strict_types=1);

namespace App\Domains\Identity\Application;

use App\Domains\Identity\Contracts\ClinicianRoleDefinition;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ClinicianRoleProvisioner
{
    public function provision(string $tenantId, string $actorId, string $facilityId): string
    {
        return DB::transaction(function () use ($tenantId, $actorId, $facilityId): string {
            foreach (ClinicianRoleDefinition::PERMISSIONS as $permission) {
                if (! PermissionRegistry::has($permission)) {
                    throw new RuntimeException("Required permission '{$permission}' is not registered.");
                }
            }

            $role = DB::table('idn_roles')
                ->where('tenant_id', $tenantId)
                ->where('key', ClinicianRoleDefinition::KEY)
                ->lockForUpdate()
                ->first();
            $created = $role === null;
            $reconciled = false;

            if ($role === null) {
                $roleId = (string) Str::uuid7();
                DB::table('idn_roles')->insert([
                    'id' => $roleId,
                    'tenant_id' => $tenantId,
                    'key' => ClinicianRoleDefinition::KEY,
                    'name' => 'Clinician',
                    'status' => 'ACTIVE',
                    'scope_type' => 'FACILITY',
                    'is_protected' => true,
                    'version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach (ClinicianRoleDefinition::PERMISSIONS as $permissionKey) {
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
            } else {
                if ($role->scope_type !== 'FACILITY'
                    || $role->status !== 'ACTIVE'
                    || $role->parent_role_id !== null) {
                    throw new RuntimeException('The existing Clinician role differs from its approved scope.');
                }

                $roleId = (string) $role->id;
                $reconciled = ! $role->is_protected || $role->name !== 'Clinician';
                if ($reconciled) {
                    DB::table('idn_roles')->where('id', $roleId)->update([
                        'name' => 'Clinician',
                        'is_protected' => true,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($created || $reconciled) {
                app(AuditLogger::class)->recordFor(
                    tenantId: $tenantId,
                    action: $created
                        ? 'identity.clinician.role_provisioned'
                        : 'identity.clinician.role_label_reconciled',
                    actorId: $actorId,
                    facilityId: $facilityId,
                    metadata: [
                        'role' => ClinicianRoleDefinition::KEY,
                        'permissions_added' => $created ? ClinicianRoleDefinition::PERMISSIONS : [],
                        'protected_flag_repaired' => $reconciled && ! (bool) $role->is_protected,
                    ],
                    targetType: 'role',
                    targetId: $roleId,
                );
            }

            return $roleId;
        });
    }
}
