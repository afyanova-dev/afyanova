<?php

declare(strict_types=1);

use App\Shared\Tenancy\SystemContext;
use App\Shared\Tenancy\SystemOperation;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const ROLE_PERMISSIONS = [
        'tenant_admin' => ['inventory.items.manage'],
        'facility_admin' => ['inventory.locations.manage'],
    ];

    public function up(): void
    {
        SystemContext::run(
            SystemOperation::Migration,
            'Grant approved Inventory catalogue permissions to administrative roles.',
            function (): void {
                $permissionIds = [];
                foreach (['inventory.items.manage', 'inventory.locations.manage'] as $key) {
                    $permissionId = DB::table('idn_permissions')->where('key', $key)->value('id');
                    if (! is_string($permissionId)) {
                        $permissionId = (string) Str::uuid7();
                        DB::table('idn_permissions')->insert([
                            'id' => $permissionId,
                            'key' => $key,
                            'layer' => 'ACTION',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    $permissionIds[$key] = $permissionId;
                }

                foreach (DB::table('shared_tenants')->pluck('id') as $tenantId) {
                    TenantContextScope::run((string) $tenantId, null, function () use ($tenantId, $permissionIds): void {
                        foreach (self::ROLE_PERMISSIONS as $roleKey => $permissions) {
                            $roleIds = DB::table('idn_roles')
                                ->where('tenant_id', $tenantId)
                                ->where('key', $roleKey)
                                ->pluck('id');

                            foreach ($roleIds as $roleId) {
                                foreach ($permissions as $permission) {
                                    $exists = DB::table('idn_permission_role')
                                        ->where('tenant_id', $tenantId)
                                        ->where('role_id', $roleId)
                                        ->where('permission_id', $permissionIds[$permission])
                                        ->exists();

                                    if (! $exists) {
                                        DB::table('idn_permission_role')->insert([
                                            'id' => (string) Str::uuid7(),
                                            'tenant_id' => $tenantId,
                                            'role_id' => $roleId,
                                            'permission_id' => $permissionIds[$permission],
                                            'created_at' => now(),
                                            'updated_at' => now(),
                                        ]);
                                    }
                                }
                            }
                        }
                    });
                }
            },
        );
    }

    public function down(): void
    {
        SystemContext::run(
            SystemOperation::Migration,
            'Revoke Inventory catalogue permissions from administrative roles.',
            function (): void {
                $permissionIds = DB::table('idn_permissions')
                    ->whereIn('key', ['inventory.items.manage', 'inventory.locations.manage'])
                    ->pluck('id');
                if ($permissionIds->isEmpty()) {
                    return;
                }

                foreach (DB::table('shared_tenants')->pluck('id') as $tenantId) {
                    TenantContextScope::run((string) $tenantId, null, function () use ($tenantId, $permissionIds): void {
                        foreach (self::ROLE_PERMISSIONS as $roleKey => $_permissions) {
                            DB::table('idn_permission_role')
                                ->where('tenant_id', $tenantId)
                                ->whereIn('role_id', DB::table('idn_roles')
                                    ->select('id')
                                    ->where('tenant_id', $tenantId)
                                    ->where('key', $roleKey))
                                ->whereIn('permission_id', $permissionIds)
                                ->delete();
                        }
                    });
                }
            },
        );
    }
};
