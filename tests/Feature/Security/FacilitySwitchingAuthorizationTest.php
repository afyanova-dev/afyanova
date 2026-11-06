<?php

declare(strict_types=1);

use App\Domains\Identity\Contracts\LaboratoryCatalogEditorRoleDefinition;
use App\Domains\Identity\Contracts\TenantAdminRoleDefinition;
use App\Domains\Organization\Domain\Model\Facility;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 | Facility switching authorization tests.
 | Normal facility staff (e.g. Laboratory Technicians with auxiliary catalog editor access)
 | must be strictly confined to their assigned facility and cannot switch facilities or
 | access General context.
 | Legitimate administrators and multi-facility staff retain their authorized switching privileges.
 */

it('does not allow a laboratory technician with catalog editor role to select or switch to other facilities', function (): void {
    // Setup tenant A with a lab technician at facility A1 and a second facility A2
    ['tenant' => $tenantA, 'organization' => $orgA, 'facility' => $facilityA1, 'user' => $labTech, 'role_id' => $techRoleId] = makeTenantWithUser([
        'laboratory.access',
        'laboratory.orders.accept',
        'laboratory.results.enter',
    ]);

    // Setup facility A2 in the same tenant
    $facilityA2 = TenantContextScope::run($tenantA->id, null, function () use ($tenantA, $orgA) {
        return Facility::factory()->forTenant($tenantA->id, (string) $orgA->id)->create();
    });

    // Setup another tenant B with facility B
    ['facility' => $facilityB] = makeTenantWithUser();

    // Grant the auxiliary laboratory_catalog_editor role (tenant-wide, facility_id: null, organization_id: null)
    TenantContextScope::run($tenantA->id, null, function () use ($tenantA, $labTech) {
        $editorRoleId = (string) Str::uuid7();
        DB::table('idn_roles')->insert([
            'id' => $editorRoleId,
            'tenant_id' => $tenantA->id,
            'key' => LaboratoryCatalogEditorRoleDefinition::KEY,
            'name' => 'Laboratory Catalog Editor',
            'status' => 'ACTIVE',
            'scope_type' => 'ORG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permId = DB::table('idn_permissions')->where('key', 'laboratory.catalog.manage')->value('id')
            ?? tap((string) Str::uuid7(), fn ($id) => DB::table('idn_permissions')->insert([
                'id' => $id, 'key' => 'laboratory.catalog.manage', 'layer' => 'ACTION', 'created_at' => now(), 'updated_at' => now(),
            ]));

        DB::table('idn_permission_role')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantA->id,
            'role_id' => $editorRoleId,
            'permission_id' => $permId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantA->id,
            'user_id' => $labTech->id,
            'role_id' => $editorRoleId,
            'organization_id' => null,
            'facility_id' => null,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $scope = app(ScopeChecker::class);

    // 1. ScopeChecker checks:
    // Selectable facilities must contain ONLY facilityA1 (no facilityA2, no foreign facilityB)
    $selectable = $scope->selectableFacilities($labTech);
    expect($selectable)->toBe([(string) $facilityA1->id]);

    // General context is denied
    expect($scope->canUseGeneralContext($labTech))->toBeFalse();

    // withinFacilityScope is true for assigned facilityA1, but false for facilityA2 and foreign facilityB
    expect($scope->withinFacilityScope($labTech, (string) $facilityA1->id))->toBeTrue()
        ->and($scope->withinFacilityScope($labTech, (string) $facilityA2->id))->toBeFalse()
        ->and($scope->withinFacilityScope($labTech, (string) $facilityB->id))->toBeFalse();

    // 2. Frontend visibility:
    // When visiting dashboard, user is entered into their sole assigned facility and canUseGeneral is false
    $response = $this->actingAs($labTech)->get('/dashboard');
    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.activeFacility', (string) $facilityA1->id)
            ->where('auth.facilities', fn ($facs) => count($facs) === 1 && $facs[0]['id'] === (string) $facilityA1->id)
            ->where('auth.canUseGeneral', false));

    // 3. Direct API requests:
    // Switching to facility A2 is forbidden
    $this->actingAs($labTech)
        ->post('/facility-context', ['facility_id' => $facilityA2->id])
        ->assertForbidden();

    // Switching to foreign facility B is forbidden
    $this->actingAs($labTech)
        ->post('/facility-context', ['facility_id' => $facilityB->id])
        ->assertForbidden();

    // Switching to General mode is forbidden
    $this->actingAs($labTech)
        ->post('/facility-context', ['mode' => 'general'])
        ->assertForbidden();

    // Session remains confined to facilityA1
    expect(session('active_facility_id'))->toBe((string) $facilityA1->id);
});

it('allows legitimate tenant admin to switch between facilities in their tenant', function (): void {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility1, 'user' => $admin] = makeTenantWithUser();

    $facility2 = TenantContextScope::run($tenant->id, null, function () use ($tenant, $org) {
        return Facility::factory()->forTenant($tenant->id, (string) $org->id)->create();
    });

    ['facility' => $foreignFacility] = makeTenantWithUser();

    TenantContextScope::run($tenant->id, null, function () use ($tenant, $org, $admin) {
        $adminRoleId = (string) Str::uuid7();
        DB::table('idn_roles')->insert([
            'id' => $adminRoleId,
            'tenant_id' => $tenant->id,
            'key' => TenantAdminRoleDefinition::KEY,
            'name' => 'Tenant Admin',
            'status' => 'ACTIVE',
            'scope_type' => 'ORG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (TenantAdminRoleDefinition::PERMISSIONS as $permKey) {
            $permId = DB::table('idn_permissions')->where('key', $permKey)->value('id')
                ?? tap((string) Str::uuid7(), fn ($id) => DB::table('idn_permissions')->insert([
                    'id' => $id, 'key' => $permKey, 'layer' => str_ends_with($permKey, '.access') ? 'ACCESS' : 'ACTION',
                    'created_at' => now(), 'updated_at' => now(),
                ]));

            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant->id,
                'role_id' => $adminRoleId,
                'permission_id' => $permId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'role_id' => $adminRoleId,
            'organization_id' => $org->id,
            'facility_id' => null,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $scope = app(ScopeChecker::class);
    $selectable = $scope->selectableFacilities($admin);

    // Can select both facility1 and facility2, but not foreignFacility
    expect($selectable)->toContain((string) $facility1->id)
        ->and($selectable)->toContain((string) $facility2->id)
        ->and($selectable)->not->toContain((string) $foreignFacility->id);

    expect($scope->canUseGeneralContext($admin))->toBeTrue();

    // Admin can switch to facility2
    $this->actingAs($admin)
        ->post('/facility-context', ['facility_id' => $facility2->id])
        ->assertRedirect()
        ->assertSessionHas('active_facility_id', (string) $facility2->id);

    // Admin can switch to General
    $this->actingAs($admin)
        ->post('/facility-context', ['mode' => 'general'])
        ->assertRedirect()
        ->assertSessionHas('facility_context_mode', 'general');

    // Admin cannot switch to foreign facility
    $this->actingAs($admin)
        ->post('/facility-context', ['facility_id' => $foreignFacility->id])
        ->assertForbidden();
});

it('allows multi-facility staff to switch only between their explicitly assigned facilities', function (): void {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility1, 'user' => $staff, 'role_id' => $roleId] = makeTenantWithUser([
        'clinical.access',
    ]);

    $facility2 = TenantContextScope::run($tenant->id, null, function () use ($tenant, $org, $staff, $roleId) {
        $facility = Facility::factory()->forTenant($tenant->id, (string) $org->id)->create();
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'user_id' => $staff->id,
            'role_id' => $roleId,
            'organization_id' => $org->id,
            'facility_id' => $facility->id,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $facility;
    });

    $unassignedFacility = TenantContextScope::run($tenant->id, null, function () use ($tenant, $org) {
        return Facility::factory()->forTenant($tenant->id, (string) $org->id)->create();
    });

    $scope = app(ScopeChecker::class);
    $selectable = $scope->selectableFacilities($staff);

    expect($selectable)->toHaveCount(2)
        ->and($selectable)->toContain((string) $facility1->id)
        ->and($selectable)->toContain((string) $facility2->id)
        ->and($selectable)->not->toContain((string) $unassignedFacility->id);

    // Can switch between assigned facilities
    $this->actingAs($staff)
        ->post('/facility-context', ['facility_id' => $facility2->id])
        ->assertRedirect()
        ->assertSessionHas('active_facility_id', (string) $facility2->id);

    // Cannot switch to unassigned facility
    $this->actingAs($staff)
        ->post('/facility-context', ['facility_id' => $unassignedFacility->id])
        ->assertForbidden();
});
