<?php

declare(strict_types=1);

use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 3 gate — User → staff identity → Access Assignment {scope + role} →
 | Permissions (blueprint.md §8; docs/00-foundation/03-identity-rbac.md §6,
 | §11–§19, §35–§38, §66, §77). Scope is data on the assignment, never inferred.
 */

/**
 * A tenant with one organisation, two facilities, and a user.
 *
 * @return array{tenant: Tenant, user: User, orgA: string, facA: string, deptA: string, facB: string}
 */
function identityFixture(): array
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant->id)->create();

    return TenantContextScope::run($tenant->id, null, function () use ($tenant, $user): array {
        $orgA = Organization::factory()->forTenant($tenant->id)->create();
        $facA = Facility::factory()->forTenant($tenant->id, $orgA->id)->create();
        $facB = Facility::factory()->forTenant($tenant->id, $orgA->id)->create();
        $deptA = DB::table('org_departments')->insertGetId([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'facility_id' => $facA->id,
            'name' => 'Ward', 'code' => 'WARD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // insertGetId on a uuid pk returns 0 on pgsql — fetch it back
        $deptAId = DB::table('org_departments')->where('facility_id', $facA->id)->where('code', 'WARD')->value('id');
        DB::table('idn_staff_identities')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'is_staff' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'tenant' => $tenant, 'user' => $user,
            'orgA' => $orgA->id, 'facA' => $facA->id, 'deptA' => (string) $deptAId,
            'facB' => $facB->id,
        ];
    });
}

function makeRole(string $tenantId, string $key, array $permKeys, string $status = 'ACTIVE', ?string $parentId = null): string
{
    $roleId = (string) Str::uuid7();
    DB::table('idn_roles')->insert([
        'id' => $roleId, 'tenant_id' => $tenantId, 'key' => $key, 'name' => Str::headline($key),
        'status' => $status, 'scope_type' => 'FACILITY', 'parent_role_id' => $parentId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($permKeys as $permKey) {
        $permId = DB::table('idn_permissions')->where('key', $permKey)->value('id')
            ?? tap((string) Str::uuid7(), fn (string $id) => DB::table('idn_permissions')->insert([
                'id' => $id, 'key' => $permKey, 'layer' => str_ends_with($permKey, '.access') ? 'ACCESS' : 'ACTION',
                'created_at' => now(), 'updated_at' => now(),
            ]));
        DB::table('idn_permission_role')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'role_id' => $roleId,
            'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $roleId;
}

/** @param array<string, mixed> $scope */
function assign(string $tenantId, string $userId, string $roleId, array $scope = []): string
{
    $id = (string) Str::uuid7();
    DB::table('idn_access_assignments')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'role_id' => $roleId,
        'status' => $scope['status'] ?? 'ACTIVE',
        'organization_id' => $scope['organization_id'] ?? null,
        'facility_id' => $scope['facility_id'] ?? null,
        'department_id' => $scope['department_id'] ?? null,
        'unit_id' => $scope['unit_id'] ?? null,
        'effective_from' => $scope['effective_from'] ?? null,
        'effective_to' => $scope['effective_to'] ?? null,
        'is_primary' => $scope['is_primary'] ?? false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('resolves org-, facility-, department- and unit-scoped grants against the acting context', function (): void {
    ['tenant' => $t, 'user' => $u, 'orgA' => $orgA, 'facA' => $facA, 'deptA' => $deptA, 'facB' => $facB] = identityFixture();

    TenantContextScope::run($t->id, null, function () use ($t, $u, $orgA, $facA, $deptA, $facB): void {
        $orgRole = makeRole($t->id, 'org_reader', ['identity.users.read']);
        $facRole = makeRole($t->id, 'fac_manager', ['identity.users.manage']);
        $deptRole = makeRole($t->id, 'ward_clerk', ['identity.assignments.read']);

        assign($t->id, $u->id, $orgRole, ['organization_id' => $orgA]);
        assign($t->id, $u->id, $facRole, ['facility_id' => $facA]);
        assign($t->id, $u->id, $deptRole, ['facility_id' => $facA, 'department_id' => $deptA]);

        $scope = app(ScopeChecker::class);

        // in facility A (org A) — the org grant + facility grant apply
        expect($scope->effectivePermissions($u, $facA))
            ->toContain('identity.users.read')->toContain('identity.users.manage')
            ->not->toContain('identity.assignments.read'); // dept grant needs the dept context

        // department context adds the dept-scoped grant
        expect($scope->effectivePermissions($u, $facA, $deptA))->toContain('identity.assignments.read');

        // organization grants reach every facility in the sole organization;
        // facility and department grants remain limited to facility A.
        expect($scope->effectivePermissions($u, $facB))
            ->toContain('identity.users.read')
            ->not->toContain('identity.users.manage')
            ->not->toContain('identity.assignments.read');

        // no facility selected — a facility/org-scoped grant does not resolve
        expect($scope->effectivePermissions($u, null))->toBe([]);
    });
});

it('allows department-assigned workspace access in its facility without widening action permissions', function (): void {
    ['tenant' => $t, 'user' => $u, 'facA' => $facA, 'deptA' => $deptA] = identityFixture();

    TenantContextScope::run($t->id, $facA, function () use ($t, $u, $facA, $deptA): void {
        $role = makeRole($t->id, 'department_pharmacist', [
            'pharmacy.access',
            'inventory.access',
            'procurement.access',
            'inventory.receipts.create',
        ]);
        assign($t->id, $u->id, $role, ['facility_id' => $facA, 'department_id' => $deptA]);

        expect($u->can('pharmacy.access'))->toBeTrue()
            ->and($u->can('inventory.access'))->toBeTrue()
            ->and($u->can('inventory.receipts.create'))->toBeFalse()
            ->and(app(ScopeChecker::class)->hasFacilityPermission($u, $facA, 'inventory.access'))->toBeTrue()
            ->and(app(ScopeChecker::class)->hasFacilityPermission($u, $facA, 'procurement.access'))->toBeTrue()
            ->and(app(ScopeChecker::class)->hasFacilityPermission($u, $facA, 'inventory.receipts.create'))->toBeFalse();
    });
});

it('stops granting the moment an assignment is REVOKED, EXPIRED, or outside its date window (inv. 6)', function (): void {
    ['tenant' => $t, 'user' => $u, 'facA' => $facA] = identityFixture();

    TenantContextScope::run($t->id, null, function () use ($t, $u, $facA): void {
        $role = makeRole($t->id, 'clerk', ['identity.users.read']);
        $scope = app(ScopeChecker::class);

        $a = assign($t->id, $u->id, $role, ['facility_id' => $facA]);
        expect($scope->effectivePermissions($u, $facA))->toContain('identity.users.read');

        DB::table('idn_access_assignments')->where('id', $a)->update(['status' => 'REVOKED']);
        expect($scope->effectivePermissions($u, $facA))->toBe([]);

        DB::table('idn_access_assignments')->where('id', $a)->update(['status' => 'EXPIRED']);
        expect($scope->effectivePermissions($u, $facA))->toBe([]);

        DB::table('idn_access_assignments')->where('id', $a)->update([
            'status' => 'ACTIVE', 'effective_from' => now()->subDays(10), 'effective_to' => now()->subDay(),
        ]);
        expect($scope->effectivePermissions($u, $facA))->toBe([]); // window closed yesterday
    });
});

it('honours a temporary assignment only within [effective_from, effective_to] (§38)', function (): void {
    ['tenant' => $t, 'user' => $u, 'facA' => $facA] = identityFixture();

    TenantContextScope::run($t->id, null, function () use ($t, $u, $facA): void {
        $role = makeRole($t->id, 'locum', ['identity.users.read']);
        assign($t->id, $u->id, $role, [
            'facility_id' => $facA, 'effective_from' => now()->subDay(), 'effective_to' => now()->addDay(),
        ]);

        expect(app(ScopeChecker::class)->effectivePermissions($u, $facA))->toContain('identity.users.read');
    });
});

it('grants nothing through a RETIRED role (§35)', function (): void {
    ['tenant' => $t, 'user' => $u, 'facA' => $facA] = identityFixture();

    TenantContextScope::run($t->id, null, function () use ($t, $u, $facA): void {
        $role = makeRole($t->id, 'old_role', ['identity.users.read'], status: 'RETIRED');
        assign($t->id, $u->id, $role, ['facility_id' => $facA]);

        expect(app(ScopeChecker::class)->effectivePermissions($u, $facA))->toBe([]);
    });
});

it('inherits a parent role\'s permissions but never its scope (inv. 5)', function (): void {
    ['tenant' => $t, 'user' => $u, 'facA' => $facA, 'facB' => $facB] = identityFixture();

    TenantContextScope::run($t->id, null, function () use ($t, $u, $facA, $facB): void {
        $parent = makeRole($t->id, 'senior', ['identity.roles.manage']);
        $child = makeRole($t->id, 'junior', ['identity.roles.read'], parentId: $parent);

        // the user holds only the CHILD, and only at facility A
        assign($t->id, $u->id, $child, ['facility_id' => $facA]);

        $scope = app(ScopeChecker::class);
        expect($scope->effectivePermissions($u, $facA))
            ->toContain('identity.roles.read')       // own
            ->toContain('identity.roles.manage');    // inherited from parent

        // the parent is not assigned anywhere → no widening to facility B
        expect($scope->effectivePermissions($u, $facB))->toBe([]);
    });
});

it('narrows the effective set to the session active role when one is chosen (§17–§18)', function (): void {
    ['tenant' => $t, 'user' => $u, 'facA' => $facA] = identityFixture();

    TenantContextScope::run($t->id, null, function () use ($t, $u, $facA): void {
        $roleA = makeRole($t->id, 'role_a', ['identity.users.read']);
        $roleB = makeRole($t->id, 'role_b', ['identity.users.manage']);
        assign($t->id, $u->id, $roleA, ['facility_id' => $facA]);
        assign($t->id, $u->id, $roleB, ['facility_id' => $facA]);

        $scope = app(ScopeChecker::class);
        expect($scope->effectivePermissions($u, $facA))
            ->toContain('identity.users.read')->toContain('identity.users.manage');

        expect($scope->effectivePermissions($u, $facA, null, null, $roleA))
            ->toBe(['identity.users.read']);
    });
});

it('separates a staff user from a non-staff principal (D-1)', function (): void {
    ['tenant' => $t, 'user' => $staff, 'facA' => $facA] = identityFixture();
    $auditor = User::factory()->forTenant($t->id)->create();

    TenantContextScope::run($t->id, null, function () use ($t, $auditor, $facA): void {
        $role = makeRole($t->id, 'inspector', ['identity.users.read']);
        assign($t->id, $auditor->id, $role, ['facility_id' => $facA]);
    });

    $api = app(IdentityApi::class);

    TenantContextScope::run($t->id, $facA, function () use ($api, $staff, $auditor, $facA): void {
        expect($api->isStaff($staff->id))->toBeTrue()
            ->and($api->isStaff($auditor->id))->toBeFalse()
            // the non-staff auditor still holds an assignment and its permission
            ->and($api->effectivePermissions($auditor->id, $facA))->toContain('identity.users.read');
    });
});
