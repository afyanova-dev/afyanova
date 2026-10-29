<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Security-test helper. Build a tenant → organisation → facility, a user with a
 * staff identity, and an ACTIVE facility-scoped access assignment to a role that
 * holds the given permission keys, plus (optionally) enabled capabilities.
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $capabilities
 * @return array{tenant: Tenant, organization: Organization, facility: Facility, user: User, role_id: string}
 */
function makeTenantWithUser(array $permissions = [], array $capabilities = [], bool $verified = true, string $status = 'ACTIVE'): array
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant->id)->create([
        'email_verified_at' => $verified ? now() : null,
        'status' => $status,
    ]);

    /** @var array{organization: Organization, facility: Facility, role_id: string} $built */
    $built = TenantContextScope::run($tenant->id, null, function () use ($tenant, $user, $permissions, $capabilities): array {
        $org = Organization::factory()->forTenant($tenant->id)->create();
        $facility = Facility::factory()->forTenant($tenant->id, $org->id)->create();

        $roleId = (string) Str::uuid7();
        DB::table('idn_roles')->insert([
            'id' => $roleId, 'tenant_id' => $tenant->id, 'key' => 'test_role', 'name' => 'Test',
            'status' => 'ACTIVE', 'scope_type' => 'FACILITY', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($permissions as $key) {
            $permId = DB::table('idn_permissions')->where('key', $key)->value('id')
                ?? tap((string) Str::uuid7(), fn (string $id) => DB::table('idn_permissions')->insert([
                    'id' => $id, 'key' => $key, 'layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION',
                    'created_at' => now(), 'updated_at' => now(),
                ]));

            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id,
                'role_id' => $roleId, 'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('idn_staff_identities')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'is_staff' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id,
            'user_id' => $user->id, 'role_id' => $roleId, 'facility_id' => $facility->id,
            'status' => 'ACTIVE', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($capabilities as $cap) {
            DB::table('org_facility_capabilities')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'facility_id' => $facility->id,
                'capability_code' => $cap, 'state' => 'ENABLED', 'enabled_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return ['organization' => $org, 'facility' => $facility, 'role_id' => $roleId];
    });

    return [
        'tenant' => $tenant,
        'organization' => $built['organization'],
        'facility' => $built['facility'],
        'user' => $user,
        'role_id' => $built['role_id'],
    ];
}
