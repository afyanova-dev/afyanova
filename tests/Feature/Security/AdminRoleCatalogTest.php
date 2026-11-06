<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function createForeignRoleForAdminRoleCatalogTest(Tenant $tenant): void
{
    TenantContextScope::run($tenant->id, null, function () use ($tenant): void {
        DB::table('idn_roles')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'key' => 'foreign_role',
            'name' => 'Foreign Role',
            'status' => 'ACTIVE',
            'scope_type' => 'FACILITY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

/**
 * @param  array{tenant: Tenant, organization: Organization, facility: Facility, user: User, role_id: string}  $fixture
 */
function moveRoleCatalogFixtureToOrganizationScope(array $fixture): void
{
    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture): void {
        DB::table('idn_roles')
            ->where('id', $fixture['role_id'])
            ->update(['scope_type' => 'ORG']);

        DB::table('idn_access_assignments')
            ->where('user_id', $fixture['user']->getKey())
            ->where('role_id', $fixture['role_id'])
            ->update([
                'organization_id' => $fixture['organization']->getKey(),
                'facility_id' => null,
            ]);
    });
}

it('shows role permissions only to authorized admins in their tenant', function (): void {
    $fixture = makeTenantWithUser([
        'admin.workspace.access',
        'identity.roles.read',
        'inventory.access',
    ]);
    $fixture['user']->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();
    $foreignTenant = makeTenantWithUser()['tenant'];
    createForeignRoleForAdminRoleCatalogTest($foreignTenant);

    $this->actingAs($fixture['user'])
        ->get(route('admin.identity.role-catalog'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Identity/RoleCatalog')
            ->has('roles.data', 1)
            ->where('roles.data.0.key', 'test_role')
            ->has('roles.data.0.permissions', 3)
            ->where('roles.data.0.permissions.2.key', 'inventory.access')
        );
});

it('allows organization-scoped role readers to inspect the catalogue', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access', 'identity.roles.read']);
    $fixture['user']->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();
    moveRoleCatalogFixtureToOrganizationScope($fixture);

    $this->actingAs($fixture['user'])
        ->get(route('admin.identity.role-catalog'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Identity/RoleCatalog')
            ->has('roles.data', 1)
            ->where('roles.data.0.key', 'test_role')
        );
});

it('forbids role catalogue access without the role read permission', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access']);
    $fixture['user']->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($fixture['user'])
        ->get(route('admin.identity.role-catalog'))
        ->assertForbidden();
});
