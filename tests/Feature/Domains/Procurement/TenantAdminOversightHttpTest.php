<?php

declare(strict_types=1);

use App\Domains\Identity\Contracts\TenantAdminRoleDefinition;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Procurement workspace → Tenant Admin read-only oversight (Proc.5).
 *
 * `TenantAdminRoleDefinition` carries the coarse READ key
 * `procurement.access` but none of the write keys, so the Org-scoped Tenant
 * Admin enters the workspace and observes it across every facility
 * (ScopeChecker's NULL-facility wildcard) without becoming a procurement
 * actor — §24 / §228 different-actor guards stay untouched. These tests pin
 * the HTTP wiring: the supplier master reads from General context, the
 * facility sections read after a facility switch, and every write refuses.
 */

/**
 * Build a tenant + organization + facility and a user assigned the canonical
 * `tenant_admin` role bundle as a real ORG-scoped assignment (`facility_id`
 * NULL), the shape `AdminRolePromotionController` provisions.
 *
 * @return array{t: string, user: User, org: string, facility: string}
 */
function tenantAdminOversightFixture(): array
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant->id)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);

    $scope = TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $user): array {
        $organization = Organization::factory()->forTenant($tenant->id)->create();
        $facility = Facility::factory()->forTenant($tenant->id, (string) $organization->id)->create();

        $roleId = (string) Str::uuid7();
        DB::table('idn_roles')->insert([
            'id' => $roleId,
            'tenant_id' => $tenant->id,
            'key' => TenantAdminRoleDefinition::KEY,
            'name' => 'Tenant Administrator',
            'status' => 'ACTIVE',
            'scope_type' => 'ORG',
            'is_protected' => true,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (TenantAdminRoleDefinition::PERMISSIONS as $key) {
            if (! PermissionRegistry::has($key)) {
                continue;
            }

            $permissionId = DB::table('idn_permissions')->where('key', $key)->value('id')
                ?? tap((string) Str::uuid7(), fn (string $id) => DB::table('idn_permissions')->insert([
                    'id' => $id, 'key' => $key, 'layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION',
                    'created_at' => now(), 'updated_at' => now(),
                ]));

            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id,
                'role_id' => $roleId, 'permission_id' => $permissionId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('idn_staff_identities')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'is_staff' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id,
            'user_id' => $user->id, 'role_id' => $roleId,
            'organization_id' => (string) $organization->id,
            'facility_id' => null,
            'status' => 'ACTIVE', 'is_primary' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['org' => (string) $organization->id, 'facility' => (string) $facility->id];
    });

    test()->actingAs($user);

    return [
        't' => (string) $tenant->id,
        'user' => $user,
        'org' => $scope['org'],
        'facility' => $scope['facility'],
    ];
}

it('lets a tenant admin read the supplier master from General context without write affordances', function (): void {
    $fixture = tenantAdminOversightFixture();
    seedSupplier($fixture['t'], ['code' => 'S-1', 'name' => 'Acme Medical']);

    $this->get('/workspaces/procurement')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Index')
            ->where('section', 'suppliers')
            ->where('canManageSuppliers', false)
            ->where('canCreateOrders', false)
            ->where('canCreateRequests', false)
            ->has('suppliers', 1)
            ->where('suppliers.0.code', 'S-1')
            ->where('suppliers.0.name', 'Acme Medical'));
});

it('asks for a facility instead of returning a 403 for a direct facility-section link from General', function (): void {
    $fixture = tenantAdminOversightFixture();

    $this->get('/workspaces/procurement?section=orders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Index')
            ->where('section', 'orders')
            ->where('facilityContextRequired', true)
            ->has('purchaseOrders', 0)
            ->has('purchaseRequisitions', 0));
});

it('shows the requests section read-only once the tenant admin switches into a facility', function (): void {
    $fixture = tenantAdminOversightFixture();
    $departmentId = (string) Str::uuid7();
    TenantContextScope::run($fixture['t'], null, function () use ($fixture, $departmentId): void {
        DB::table('org_departments')->insert([
            'id' => $departmentId,
            'tenant_id' => $fixture['t'],
            'facility_id' => $fixture['facility'],
            'name' => 'Laboratory',
            'code' => 'LAB',
            'status' => 'ACTIVE',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->withSession(['active_facility_id' => $fixture['facility']])
        ->get('/workspaces/procurement?section=requests')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Index')
            ->where('section', 'requests')
            ->where('canCreateRequests', false)
            ->where('canCreateOrders', false)
            ->where('canManageSuppliers', false));
});

it('refuses every procurement write from a tenant admin', function (): void {
    $fixture = tenantAdminOversightFixture();

    $this->post('/workspaces/procurement/suppliers', [
        'name' => 'Acme Medical',
        'code' => 'ACME-01',
    ])->assertForbidden();

    $this->withSession(['active_facility_id' => $fixture['facility']])
        ->post('/workspaces/procurement/requests', [
            'priority' => 'ROUTINE',
            'reason' => 'Monthly reagents',
            'lines' => [[
                'item_id' => (string) Str::uuid7(),
                'quantity' => '1',
            ]],
        ])->assertForbidden();

    expect(TenantContextScope::run($fixture['t'], null, fn () => Supplier::query()->count()))->toBe(0);
});
