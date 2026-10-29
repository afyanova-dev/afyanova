<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Procurement\Domain\Model\ApprovalPolicy;
use App\Models\User;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Admin → Procurement limits (Shape B).
 *
 * The rule under test is WHO may edit WHAT: a Facility Admin only their own
 * facility, a Tenant Admin every facility in the tenant. It is decided per
 * facility, not by a role name, and it is enforced twice — the page scopes the
 * list, the command bus re-checks the write.
 *
 * The fixture facility is a HEALTH_CENTRE, whose default limit is TZS 1,000,000.
 *
 * @param  list<string>  $permissions
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: User}
 */
function approvalLimitsAdmin(array $permissions = []): array
{
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user] = makeTenantWithUser($permissions);

    $user->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb', 'cccc-dddd'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user];
}

/**
 * Give a user a TENANT-WIDE assignment (no organization, no facility) — the
 * shape a Tenant Admin has, which reaches every facility in the tenant.
 *
 * @param  list<string>  $keys
 */
function grantTenantWideApprovalLimits(string $tenantId, string $userId, array $keys): void
{
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $userId, $keys): void {
        $roleId = (string) Str::uuid7();

        DB::table('idn_roles')->insert([
            'id' => $roleId, 'tenant_id' => $tenantId, 'key' => 'tenant_admin_test',
            'name' => 'Tenant Administrator', 'status' => 'ACTIVE', 'scope_type' => 'ORG',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($keys as $key) {
            $permissionId = DB::table('idn_permissions')->where('key', $key)->value('id');

            if (! is_string($permissionId)) {
                $permissionId = (string) Str::uuid7();
                DB::table('idn_permissions')->insert([
                    'id' => $permissionId, 'key' => $key,
                    'layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
                'role_id' => $roleId, 'permission_id' => $permissionId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('idn_staff_identities')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $userId,
            'is_staff' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $userId,
            'role_id' => $roleId, 'organization_id' => null, 'facility_id' => null,
            'status' => 'ACTIVE', 'is_primary' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });
}

/**
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: User}
 */
function makeSecondFacility(string $tenantId, string $organizationId): array
{
    $facility = TenantContextScope::run($tenantId, null, fn (): Facility => Facility::factory()
        ->forTenant($tenantId, $organizationId)
        ->create(['name' => 'Second Facility', 'code' => 'SECOND']));

    return ['facility' => $facility];
}

function seedDepartment(string $tenantId, string $facilityId, string $code): string
{
    $departmentId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $departmentId, $code): void {
        DB::table('org_departments')->insert([
            'id' => $departmentId, 'tenant_id' => $tenantId, 'facility_id' => $facilityId,
            'name' => 'Department '.$code, 'code' => $code, 'status' => 'ACTIVE',
            'version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    return $departmentId;
}

it('shows a Facility Admin only their own facility, with the facility-type default', function (): void {
    $fixture = approvalLimitsAdmin(['admin.workspace.access', 'procurement.approval_limits.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    makeSecondFacility($tenantId, (string) $fixture['organization']->id);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/admin/procurement-limits')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/ProcurementLimits/Index')
            ->has('facilities', 1)
            ->where('facilities.0.id', $facilityId)
            ->where('facilities.0.limit', null)
            ->where('facilities.0.effectiveLimit', '1000000.00')
            ->where('typeDefaults.HEALTH_CENTRE', '1000000'));
});

it('lets a Facility Admin set their own facility limit, and audits it', function (): void {
    $fixture = approvalLimitsAdmin(['admin.workspace.access', 'procurement.approval_limits.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/admin/procurement-limits', [
            'facility_id' => $facilityId,
            'limit' => '450000',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $row = TenantContextScope::run($tenantId, null, fn () => ApprovalPolicy::query()->first());

    expect($row)->not->toBeNull()
        ->and((string) $row->facility_id)->toBe($facilityId)
        ->and($row->department_ref)->toBeNull()
        ->and((string) $row->self_approval_limit)->toBe('450000.00');

    $audits = Tenancy::run($tenantId, fn () => DB::table('shared_audit_log')
        ->where('action', 'procurement.approval_limit_configured')
        ->count());
    expect($audits)->toBe(1);
});

it('refuses a Facility Admin writing another facility limit', function (): void {
    $fixture = approvalLimitsAdmin(['admin.workspace.access', 'procurement.approval_limits.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $other = makeSecondFacility($tenantId, (string) $fixture['organization']->id)['facility'];

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => (string) $fixture['facility']->id])
        ->post('/workspaces/admin/procurement-limits', [
            'facility_id' => (string) $other->id,
            'limit' => '9000000',
        ])
        ->assertForbidden();

    expect(TenantContextScope::run($tenantId, null, fn () => ApprovalPolicy::query()->count()))->toBe(0);
});

it('gives a Tenant Admin every facility and lets them set any of them', function (): void {
    $fixture = approvalLimitsAdmin([]);
    $tenantId = (string) $fixture['tenant']->id;
    $organizationId = (string) $fixture['organization']->id;
    $other = makeSecondFacility($tenantId, $organizationId)['facility'];

    grantTenantWideApprovalLimits($tenantId, (string) $fixture['user']->id, [
        'admin.workspace.access', 'procurement.approval_limits.manage',
    ]);

    $this->actingAs($fixture['user'])
        ->get('/workspaces/admin/procurement-limits')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('facilities', 2)
            ->where('facilities.0.id', (string) $fixture['facility']->id)
            ->where('facilities.1.id', (string) $other->id));

    $this->actingAs($fixture['user'])
        ->post('/workspaces/admin/procurement-limits', [
            'facility_id' => (string) $other->id,
            'limit' => '750000',
        ])
        ->assertRedirect();

    $row = TenantContextScope::run($tenantId, null, fn () => ApprovalPolicy::query()
        ->where('facility_id', $other->id)
        ->first());

    expect($row)->not->toBeNull()
        ->and((string) $row->self_approval_limit)->toBe('750000.00');
});

it('narrows a Tenant Admin in a facility context to just the active facility', function (): void {
    $fixture = approvalLimitsAdmin([]);
    $tenantId = (string) $fixture['tenant']->id;
    $organizationId = (string) $fixture['organization']->id;
    $other = makeSecondFacility($tenantId, $organizationId)['facility'];

    grantTenantWideApprovalLimits($tenantId, (string) $fixture['user']->id, [
        'admin.workspace.access', 'procurement.approval_limits.manage',
    ]);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => (string) $other->id])
        ->get('/workspaces/admin/procurement-limits')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('facilities', 1)
            ->where('facilities.0.id', (string) $other->id));
});

it('sets and then removes a department limit', function (): void {
    $fixture = approvalLimitsAdmin(['admin.workspace.access', 'procurement.approval_limits.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $laboratory = seedDepartment($tenantId, $facilityId, 'LAB');

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/admin/procurement-limits', [
            'facility_id' => $facilityId,
            'department_id' => $laboratory,
            'limit' => '120000',
        ])
        ->assertRedirect();

    $row = TenantContextScope::run($tenantId, null, fn () => ApprovalPolicy::query()->first());
    expect((string) $row->department_ref)->toBe($laboratory);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/admin/procurement-limits')
        ->assertInertia(fn (Assert $page) => $page
            ->where('facilities.0.departments.0.id', $laboratory)
            ->where('facilities.0.departments.0.limit', '120000.00')
            ->where('facilities.0.departments.0.effectiveLimit', '120000.00'));

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/admin/procurement-limits', [
            'facility_id' => $facilityId,
            'department_id' => $laboratory,
            'clear' => true,
        ])
        ->assertRedirect();

    expect(TenantContextScope::run($tenantId, null, fn () => ApprovalPolicy::query()->count()))->toBe(0);
});

it('rejects a department that belongs to another facility', function (): void {
    $fixture = approvalLimitsAdmin(['admin.workspace.access', 'procurement.approval_limits.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $other = makeSecondFacility($tenantId, (string) $fixture['organization']->id)['facility'];
    $foreignDepartment = seedDepartment($tenantId, (string) $other->id, 'OPD');

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => (string) $fixture['facility']->id])
        ->post('/workspaces/admin/procurement-limits', [
            'facility_id' => (string) $fixture['facility']->id,
            'department_id' => $foreignDepartment,
            'limit' => '100000',
        ])
        ->assertSessionHasErrors('department_id');

    expect(TenantContextScope::run($tenantId, null, fn () => ApprovalPolicy::query()->count()))->toBe(0);
});

it('denies the screen without the approval-limits permission', function (): void {
    $fixture = approvalLimitsAdmin(['admin.workspace.access']);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => (string) $fixture['facility']->id])
        ->get('/workspaces/admin/procurement-limits')
        ->assertForbidden();
});
