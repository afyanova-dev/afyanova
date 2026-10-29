<?php

declare(strict_types=1);

use App\Shared\Tenancy\SystemContext;
use App\Shared\Tenancy\SystemOperation;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 8 — stock adjustments were unusable in a real tenant: the commands,
 * routes, screens and policies existed, but no seeded role held
 * `inventory.adjustments.create` or `.approve`, so the expiry removal and the
 * adjustment worklist were read-only for everyone.
 *
 * Stock counts close that loop: completing a count raises one pending
 * correction per difference, and a second person approves it. Grant the two
 * keys to the Facility Admin and the Pharmacist — two roles, so two different
 * people can complete the pair, while the existing self-approval guard still
 * stops one person doing both.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'inventory.adjustments.create',
        'inventory.adjustments.approve',
    ];

    /** @var list<string> */
    private const ROLES = ['facility_admin', 'pharmacist'];

    public function up(): void
    {
        SystemContext::run(
            SystemOperation::Migration,
            'Grant stock adjustments to Facility Admin and Pharmacist roles.',
            function (): void {
                foreach (self::PERMISSIONS as $key) {
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
                }
            },
        );
    }

    public function down(): void
    {
        SystemContext::run(
            SystemOperation::Migration,
            'Revoke stock adjustments from Facility Admin and Pharmacist roles.',
            function (): void {
                $permissionIds = DB::table('idn_permissions')
                    ->whereIn('key', self::PERMISSIONS)
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
                                ->whereIn('key', self::ROLES))
                            ->whereIn('permission_id', $permissionIds)
                            ->delete();
                    });
                }
            },
        );
    }
};
