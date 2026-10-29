<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (! function_exists('createReceptionUser')) {
    /**
     * @param  list<string>  $permissions
     */
    function createReceptionUser(string $tenantId, string $facilityId, array $permissions): User
    {
        $user = User::factory()->forTenant($tenantId)->create(['status' => 'ACTIVE']);

        TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $user, $permissions): void {
            $roleId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $roleId,
                'tenant_id' => $tenantId,
                'key' => 'reception_role_'.Str::random(6),
                'name' => 'Reception Test Role',
                'status' => 'ACTIVE',
                'scope_type' => 'FACILITY',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($permissions as $key) {
                $permId = DB::table('idn_permissions')->where('key', $key)->value('id')
                    ?? tap((string) Str::uuid7(), fn (string $id) => DB::table('idn_permissions')->insert([
                        'id' => $id,
                        'key' => $key,
                        'layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));

                DB::table('idn_permission_role')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'role_id' => $roleId,
                    'permission_id' => $permId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('idn_staff_identities')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'is_staff' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('idn_access_assignments')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'role_id' => $roleId,
                'facility_id' => $facilityId,
                'status' => 'ACTIVE',
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $user;
    }
}
