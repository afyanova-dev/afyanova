<?php

declare(strict_types=1);

use App\Shared\Tenancy\SystemContext;
use App\Shared\Tenancy\SystemOperation;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 8 — grant stock transfers to the roles that physically move stock
 * between a facility's locations: the Facility Admin and the Pharmacist.
 */
return new class extends Migration
{
    private const PERMISSION = 'inventory.transfers.create';

    private const ROLES = ['facility_admin', 'pharmacist'];

    public function up(): void
    {
        SystemContext::run(
            SystemOperation::Migration,
            'Grant stock transfers to Facility Admin and Pharmacist roles.',
            function (): void {
                $permissionId = DB::table('idn_permissions')->where('key', self::PERMISSION)->value('id');
                if (! is_string($permissionId)) {
                    $permissionId = (string) Str::uuid7();
                    DB::table('idn_permissions')->insert([
                        'id' => $permissionId,
                        'key' => self::PERMISSION,
                        'layer' => 'ACTION',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach (DB::table('shared_tenants')->pluck('id') as $tenantId) {
                    TenantContextScope::run((string) $tenantId, null, function () use ($tenantId, $permissionId): void {
                        $roleIds = DB::table('idn_roles')
                            ->where('tenant_id', $tenantId)
                            ->whereIn('key', self::ROLES)
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
            'Revoke stock transfers from Facility Admin and Pharmacist roles.',
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
                                ->whereIn('key', self::ROLES))
                            ->where('permission_id', $permissionId)
                            ->delete();
                    });
                }
            },
        );
    }
};
