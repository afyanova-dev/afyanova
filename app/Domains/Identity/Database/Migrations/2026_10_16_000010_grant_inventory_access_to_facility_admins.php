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
    private const PERMISSIONS = [
        'inventory.access' => 'ACCESS',
        'inventory.receipts.create' => 'ACTION',
    ];

    public function up(): void
    {
        SystemContext::run(
            SystemOperation::Migration,
            'Grant Inventory workspace and receipt permissions to Facility Admin roles.',
            function (): void {
                $permissionIds = [];
                foreach (self::PERMISSIONS as $key => $layer) {
                    $permissionId = DB::table('idn_permissions')->where('key', $key)->value('id');
                    if (! is_string($permissionId)) {
                        $permissionId = (string) Str::uuid7();
                        DB::table('idn_permissions')->insert([
                            'id' => $permissionId,
                            'key' => $key,
                            'layer' => $layer,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    $permissionIds[$key] = $permissionId;
                }

                foreach (DB::table('shared_tenants')->pluck('id') as $tenantId) {
                    TenantContextScope::run((string) $tenantId, null, function () use ($tenantId, $permissionIds): void {
                        $roleIds = DB::table('idn_roles')
                            ->where('tenant_id', $tenantId)
                            ->where('key', 'facility_admin')
                            ->pluck('id');

                        foreach ($roleIds as $roleId) {
                            foreach ($permissionIds as $permissionId) {
                                $exists = DB::table('idn_permission_role')
                                    ->where('tenant_id', $tenantId)
                                    ->where('role_id', $roleId)
                                    ->where('permission_id', $permissionId)
                                    ->exists();

                                if (! $exists) {
                                    DB::table('idn_permission_role')->insert([
                                        'id' => (string) Str::uuid7(),
                                        'tenant_id' => $tenantId,
                                        'role_id' => $roleId,
                                        'permission_id' => $permissionId,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
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
            'Revoke Inventory workspace and receipt permissions from Facility Admin roles.',
            function (): void {
                $permissionIds = DB::table('idn_permissions')
                    ->whereIn('key', array_keys(self::PERMISSIONS))
                    ->pluck('id');
                if ($permissionIds->isEmpty()) {
                    return;
                }

                foreach (DB::table('shared_tenants')->pluck('id') as $tenantId) {
                    TenantContextScope::run((string) $tenantId, null, function () use ($tenantId, $permissionIds): void {
                        DB::table('idn_permission_role')
                            ->where('tenant_id', $tenantId)
                            ->whereIn('role_id', DB::table('idn_roles')
                                ->select('id')
                                ->where('tenant_id', $tenantId)
                                ->where('key', 'facility_admin'))
                            ->whereIn('permission_id', $permissionIds)
                            ->delete();
                    });
                }
            },
        );
    }
};
