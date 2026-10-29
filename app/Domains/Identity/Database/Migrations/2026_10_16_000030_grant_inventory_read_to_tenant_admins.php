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
    private const PERMISSION = 'inventory.access';

    public function up(): void
    {
        SystemContext::run(
            SystemOperation::Migration,
            'Grant Inventory workspace access to Tenant Admin roles managing the shared item catalogue.',
            function (): void {
                $permissionId = DB::table('idn_permissions')->where('key', self::PERMISSION)->value('id');
                if (! is_string($permissionId)) {
                    $permissionId = (string) Str::uuid7();
                    DB::table('idn_permissions')->insert([
                        'id' => $permissionId,
                        'key' => self::PERMISSION,
                        'layer' => 'ACCESS',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach (DB::table('shared_tenants')->pluck('id') as $tenantId) {
                    TenantContextScope::run((string) $tenantId, null, function () use ($tenantId, $permissionId): void {
                        $roleIds = DB::table('idn_roles')
                            ->where('tenant_id', $tenantId)
                            ->where('key', 'tenant_admin')
                            ->pluck('id');

                        foreach ($roleIds as $roleId) {
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
                    });
                }
            },
        );
    }

    public function down(): void
    {
        SystemContext::run(
            SystemOperation::Migration,
            'Revoke Inventory workspace access from Tenant Admin roles.',
            function (): void {
                $permissionId = DB::table('idn_permissions')->where('key', self::PERMISSION)->value('id');
                if (! is_string($permissionId)) {
                    return;
                }

                foreach (DB::table('shared_tenants')->pluck('id') as $tenantId) {
                    TenantContextScope::run((string) $tenantId, null, function () use ($tenantId, $permissionId): void {
                        DB::table('idn_permission_role')
                            ->where('tenant_id', $tenantId)
                            ->whereIn('role_id', DB::table('idn_roles')
                                ->select('id')
                                ->where('tenant_id', $tenantId)
                                ->where('key', 'tenant_admin'))
                            ->where('permission_id', $permissionId)
                            ->delete();
                    });
                }
            },
        );
    }
};
