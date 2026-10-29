<?php

declare(strict_types=1);

use App\Domains\Authentication\Mail\AdminBootstrapInvitation;
use App\Domains\Identity\Contracts\FacilityAdminRoleDefinition;
use App\Domains\Identity\Contracts\FacilityStaffManagerRoleDefinition;
use App\Domains\Identity\Domain\Model\Role;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

function platformFacilityAdmin(): User
{
    $user = User::factory()->state([
        'tenant_id' => null,
        'email' => fake()->unique()->safeEmail(),
    ])->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb', 'cccc-dddd'])),
        'two_factor_confirmed_at' => now(),
    ]);

    DB::table('platform_super_admins')->insert([
        'id' => (string) Str::uuid7(),
        'user_id' => $user->getKey(),
        'status' => 'ACTIVE',
        'created_by_reference' => 'OPS-PLATFORM-TEST',
        'activated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    session(['platform_access_version' => 0]);

    return $user;
}

function createFacilityAdminTestRole(string $tenantId): Role
{
    return TenantContextScope::run($tenantId, null, function () use ($tenantId): Role {
        $role = new Role([
            'tenant_id' => $tenantId,
            'key' => 'facility_admin',
            'name' => 'Facility Administrator',
            'status' => 'ACTIVE',
            'scope_type' => 'FACILITY',
        ]);
        $role->forceFill(['is_protected' => true])->save();

        foreach (FacilityAdminRoleDefinition::PERMISSIONS as $key) {
            $permissionId = DB::table('idn_permissions')->where('key', $key)->value('id');
            if (! is_string($permissionId)) {
                $permissionId = (string) Str::uuid7();
                DB::table('idn_permissions')->insert([
                    'id' => $permissionId,
                    'key' => $key,
                    'layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'role_id' => $role->getKey(),
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $role;
    });
}

function createFacilityAdminApprover(string $tenantId, string $facilityId, Role $role): User
{
    $approver = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb', 'cccc-dddd'])),
        'two_factor_confirmed_at' => now(),
    ]);

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $role, $approver): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $approver->getKey(),
            'role_id' => $role->getKey(),
            'facility_id' => $facilityId,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return $approver;
}

function createFacilityInvitationTenantAdmin(array $fixture): User
{
    $admin = User::factory()->forTenant($fixture['tenant']->id)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb', 'cccc-dddd'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $roleId = (string) Str::uuid7();

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $admin, $roleId): void {
        DB::table('idn_roles')->insert([
            'id' => $roleId,
            'tenant_id' => $fixture['tenant']->id,
            'key' => 'tenant_admin',
            'name' => 'Tenant Admin',
            'status' => 'ACTIVE',
            'scope_type' => 'ORG',
            'is_protected' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['admin.workspace.access', 'identity.assignments.read', 'identity.assignments.manage'] as $key) {
            $permissionId = DB::table('idn_permissions')->where('key', $key)->value('id');
            if (! is_string($permissionId)) {
                $permissionId = (string) Str::uuid7();
                DB::table('idn_permissions')->insert([
                    'id' => $permissionId,
                    'key' => $key,
                    'layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $fixture['tenant']->id,
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $admin->getKey(),
            'role_id' => $roleId,
            'organization_id' => $fixture['organization']->id,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return $admin;
}

function invitedFacilityAdminSetupToken(): string
{
    $mail = Mail::sent(AdminBootstrapInvitation::class)->last();
    expect($mail)->toBeInstanceOf(AdminBootstrapInvitation::class);
    preg_match('/#([A-Za-z0-9]{64})/', $mail->render(), $matches);

    expect($matches[1] ?? null)->toBeString();

    return $matches[1];
}

it('searches tenant and facility names for authenticated platform administrators', function (): void {
    $fixture = makeTenantWithUser();
    $platformAdmin = platformFacilityAdmin();
    DB::table('shared_tenants')->where('id', $fixture['tenant']->id)->update([
        'name' => 'DSK Search Network',
    ]);

    TenantContextScope::run((string) $fixture['tenant']->id, null, function () use ($fixture): void {
        DB::table('org_facilities')->where('id', $fixture['facility']->id)->update([
            'name' => 'DSK Search Dispensary',
            'code' => 'DSK-SEARCH-01',
        ]);
    });

    $this->actingAs($platformAdmin)
        ->getJson(route('platform-admin.search', ['q' => 'DSK Search']))
        ->assertOk()
        ->assertJsonPath('results.0.type', 'facility')
        ->assertJsonPath('results.0.id', (string) $fixture['facility']->id)
        ->assertJsonPath('results.0.tenantId', (string) $fixture['tenant']->id)
        ->assertJsonPath('results.0.tenantName', 'DSK Search Network');

    $this->getJson(route('platform-admin.search', ['q' => 'Network']))
        ->assertOk()
        ->assertJsonPath('results.0.type', 'tenant')
        ->assertJsonPath('results.0.id', (string) $fixture['tenant']->id);

    $this->getJson(route('platform-admin.search', ['q' => '  DSK  ']))
        ->assertOk()
        ->assertJsonPath('results.0.type', 'facility');

    $this->getJson(route('platform-admin.search', ['q' => 'D']))
        ->assertUnprocessable();
});

it('shows and updates a tenant facility with scoped, audited lifecycle changes', function (): void {
    $fixture = makeTenantWithUser();
    $platformAdmin = platformFacilityAdmin();

    DB::table('org_facility_types')->insert([
        'id' => (string) Str::uuid7(),
        'code' => 'PLATFORM_TEST_TYPE',
        'name' => 'Test facility type',
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('org_care_levels')->insert([
        'id' => (string) Str::uuid7(),
        'code' => 'PLATFORM_TEST_LEVEL',
        'name' => 'Test care level',
        'rank' => 99,
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('org_ownership_types')->insert([
        'id' => (string) Str::uuid7(),
        'code' => 'PLATFORM_TEST_OWNER',
        'name' => 'Test ownership',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($platformAdmin)
        ->get(route('platform-admin.facilities.show', [
            'tenantId' => $fixture['tenant']->id,
            'facilityId' => $fixture['facility']->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PlatformAdmin/Facilities/Show')
            ->where('facility.id', $fixture['facility']->id)
            ->where('facility.organizationName', $fixture['organization']->name));

    $this->actingAs($platformAdmin)
        ->patch(route('platform-admin.facilities.update', [
            'tenantId' => $fixture['tenant']->id,
            'facilityId' => $fixture['facility']->id,
        ]), [
            'name' => 'Updated Health Centre',
            'type' => 'PLATFORM_TEST_TYPE',
            'careLevel' => 'PLATFORM_TEST_LEVEL',
            'ownershipType' => 'PLATFORM_TEST_OWNER',
            'status' => 'SUSPENDED',
        ])
        ->assertSessionHasNoErrors();

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $platformAdmin): void {
        $facility = DB::table('org_facilities')->where('id', $fixture['facility']->id)->first();
        expect($facility->name)->toBe('Updated Health Centre')
            ->and($facility->status)->toBe('SUSPENDED')
            ->and(DB::table('shared_audit_log')
                ->where('tenant_id', $fixture['tenant']->id)
                ->where('facility_id', $fixture['facility']->id)
                ->where('actor_id', $platformAdmin->getKey())
                ->where('action', 'organization.facility.updated')
                ->exists())->toBeTrue();
    });
});

it('invites a Facility Admin, lets the inviting Tenant Admin approve, and requires approval to revoke access', function (): void {
    $fixture = makeTenantWithUser();
    $platformAdmin = platformFacilityAdmin();
    $tenantAdmin = createFacilityInvitationTenantAdmin($fixture);
    Mail::fake();

    $this->actingAs($platformAdmin)
        ->post(route('admin.identity.role-promotions.facility-admin-invitations.store'), [
            'facility_id' => $fixture['facility']->id,
            'name' => 'Facility Administrator',
            'email' => 'facility-admin@example.test',
            'change_reference' => 'OPS-FACILITY-101',
            'reason' => 'Provide local facility administration.',
        ])
        ->assertForbidden();
    $this->actingAs($platformAdmin)
        ->post("/platform-admin/tenants/{$fixture['tenant']->id}/facilities/{$fixture['facility']->id}/facility-admin-invitations")
        ->assertNotFound();

    $this->actingAs($tenantAdmin)
        ->post(route('admin.identity.role-promotions.facility-admin-invitations.store'), [
            'facility_id' => $fixture['facility']->id,
            'name' => 'Facility Administrator',
            'email' => 'facility-admin@example.test',
            'change_reference' => 'OPS-FACILITY-101',
            'reason' => 'Provide local facility administration.',
        ])
        ->assertSessionHas('status', 'Setup invitation sent. After account setup, you can approve the Facility Admin access request from the Role approvals page.')
        ->assertSessionHasNoErrors();
    $this->actingAs($tenantAdmin)
        ->get(route('admin.identity.role-promotions'))
        ->assertInertia(fn ($page) => $page->where('canInviteFacilityAdmins', true));
    Mail::assertSent(AdminBootstrapInvitation::class, fn ($mail): bool => $mail->hasTo('facility-admin@example.test'));

    $facilityAdminRole = TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture): Role {
        $role = DB::table('idn_roles')->where('tenant_id', $fixture['tenant']->id)
            ->where('key', FacilityAdminRoleDefinition::KEY)
            ->first();
        expect($role)->not->toBeNull()
            ->and((bool) $role->is_protected)->toBeTrue()
            ->and($role->scope_type)->toBe('FACILITY')
            ->and($role->status)->toBe('ACTIVE');
        $permissions = DB::table('idn_permission_role as pr')
            ->join('idn_permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('pr.role_id', $role->id)
            ->pluck('p.key')
            ->sort()
            ->values()
            ->all();
        expect($permissions)->toEqual(collect(FacilityAdminRoleDefinition::PERMISSIONS)->sort()->values()->all());
        expect(DB::table('shared_audit_log')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('facility_id', $fixture['facility']->id)
            ->where('action', 'identity.facility_admin.role_provisioned')
            ->exists())->toBeTrue();

        return Role::query()->findOrFail($role->id);
    });
    $approver = createFacilityAdminApprover($fixture['tenant']->id, $fixture['facility']->id, $facilityAdminRole);

    TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('aut_admin_bootstrap_tokens')
        ->where('purpose', 'FACILITY_ROLE')
        ->where('role_key', 'facility_admin')
        ->update(['expires_at' => now()->subMinute()]));
    $this->actingAs($tenantAdmin)
        ->post(route('admin.identity.role-promotions.facility-admin-invitations.store'), [
            'facility_id' => $fixture['facility']->id,
            'name' => 'Facility Administrator',
            'email' => 'facility-admin@example.test',
            'change_reference' => 'OPS-FACILITY-102',
            'reason' => 'Rotate the expired facility access invitation.',
        ])
        ->assertSessionHasNoErrors();

    $targetId = (string) DB::table('users')->where('email', 'facility-admin@example.test')->value('id');
    $token = invitedFacilityAdminSetupToken();
    $passwordBeforeUnauthorizedCompletion = User::query()->findOrFail($targetId)->password;
    TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('idn_access_assignments')
        ->where('user_id', $tenantAdmin->getKey())
        ->update(['status' => 'REVOKED', 'updated_at' => now()]));
    $this->post(route('admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongFacilityPassword!2026',
        'password_confirmation' => 'LongFacilityPassword!2026',
    ])->assertRedirect()->assertSessionHasErrors('token');
    expect(User::query()->findOrFail($targetId)->password)->toBe($passwordBeforeUnauthorizedCompletion);
    TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('idn_access_assignments')
        ->where('user_id', $tenantAdmin->getKey())
        ->update(['status' => 'ACTIVE', 'updated_at' => now()]));
    $this->post(route('admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongFacilityPassword!2026',
        'password_confirmation' => 'LongFacilityPassword!2026',
    ])->assertRedirect(route('login'));

    $promotion = TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('idn_admin_role_promotion_requests')
        ->where('target_user_id', $targetId)
        ->first());
    expect($promotion)->not->toBeNull()
        ->and($promotion->status)->toBe('PENDING')
        ->and($promotion->action)->toBe('PROMOTE')
        ->and($promotion->requested_by)->toBe($tenantAdmin->getKey())
        ->and($promotion->request_origin)->toBe('FACILITY_INVITATION');

    $this->actingAs($tenantAdmin)->post(route('admin.identity.role-promotions.approve', $promotion->id))
        ->assertRedirect(route('admin.identity.role-promotions'));
    $assignmentId = (string) TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('idn_access_assignments')
        ->where('user_id', $targetId)
        ->where('role_id', $facilityAdminRole->getKey())
        ->value('id'));
    expect($assignmentId)->not->toBe('');

    $selfApprovalAudit = TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('shared_audit_log')
        ->where('action', 'identity.admin_role_promotion.approved')
        ->where('target_id', $targetId)
        ->first());
    expect($selfApprovalAudit)->not->toBeNull();
    $selfApprovalMetadata = json_decode((string) $selfApprovalAudit->metadata, true, 512, JSON_THROW_ON_ERROR);
    expect($selfApprovalMetadata)
        ->toMatchArray([
            'self_approved' => true,
            'approval_mode' => 'INVITING_TENANT_ADMIN',
            'request_origin' => 'FACILITY_INVITATION',
        ]);

    $this->actingAs($platformAdmin)
        ->post(route('platform-admin.facilities.admin-revocations.store', [
            'tenantId' => $fixture['tenant']->id,
            'facilityId' => $fixture['facility']->id,
        ]), [
            'assignment_id' => $assignmentId,
            'reason' => 'Administrator access is no longer required.',
        ])
        ->assertSessionHasNoErrors();

    $revocation = TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('idn_admin_role_promotion_requests')
        ->where('assignment_id', $assignmentId)
        ->where('action', 'REVOKE')
        ->first());
    expect($revocation)->not->toBeNull()
        ->and($revocation->status)->toBe('PENDING')
        ->and(TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('idn_access_assignments')
            ->where('id', $assignmentId)->value('status')))->toBe('ACTIVE');

    $this->actingAs($approver)->post(route('admin.identity.role-promotions.approve', $revocation->id))
        ->assertRedirect(route('admin.identity.role-promotions'));
    expect(TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('idn_access_assignments')
        ->where('id', $assignmentId)->value('status')))->toBe('REVOKED');
});

it('repairs a legacy Facility Admin protected flag before inviting', function (): void {
    $fixture = makeTenantWithUser();
    $tenantAdmin = createFacilityInvitationTenantAdmin($fixture);
    $role = createFacilityAdminTestRole($fixture['tenant']->id);
    TenantContextScope::run($fixture['tenant']->id, null, fn () => $role->forceFill(['is_protected' => false])->save());
    Mail::fake();

    $this->actingAs($tenantAdmin)
        ->post(route('admin.identity.role-promotions.facility-admin-invitations.store'), [
            'facility_id' => $fixture['facility']->id,
            'name' => 'Facility Administrator',
            'email' => 'legacy-facility-admin@example.test',
            'change_reference' => 'OPS-FACILITY-LEGACY',
            'reason' => 'Repair the seeded protected role and invite administrator.',
        ])
        ->assertSessionHasNoErrors();

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $role): void {
        expect((bool) DB::table('idn_roles')->where('id', $role->getKey())->value('is_protected'))->toBeTrue()
            ->and(DB::table('shared_audit_log')
                ->where('tenant_id', $fixture['tenant']->id)
                ->where('facility_id', $fixture['facility']->id)
                ->where('action', 'identity.facility_admin.role_definition_reconciled')
                ->whereJsonContains('metadata->protected_flag_repaired', true)
                ->exists())->toBeTrue();
    });
});

it('lets the inviter approve an HR / Staff Manager invitation after setup', function (): void {
    $fixture = makeTenantWithUser();
    $tenantAdmin = createFacilityInvitationTenantAdmin($fixture);
    $facilityAdminRole = createFacilityAdminTestRole($fixture['tenant']->id);
    $facilityAdmin = createFacilityAdminApprover(
        $fixture['tenant']->id,
        $fixture['facility']->id,
        $facilityAdminRole,
    );
    Mail::fake();

    $this->actingAs($facilityAdmin)
        ->post(route('admin.identity.role-promotions.facility-user-invitations.store'), [
            'role_key' => 'facility_admin',
            'facility_id' => $fixture['facility']->id,
            'name' => 'Unauthorized Admin Invite',
            'email' => 'unauthorized-admin@example.test',
            'change_reference' => 'HR-INVITE-101',
            'reason' => 'Attempt to grant administrative access.',
        ])
        ->assertSessionHasErrors('email');

    $this->actingAs($facilityAdmin)
        ->post(route('admin.identity.role-promotions.facility-user-invitations.store'), [
            'role_key' => FacilityStaffManagerRoleDefinition::KEY,
            'facility_id' => $fixture['facility']->id,
            'name' => 'Facility HR Manager',
            'email' => 'facility-hr@example.test',
            'change_reference' => 'HR-INVITE-102',
            'reason' => 'Manage workforce records at this facility.',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Setup invitation sent. After account setup, approve the HR / Staff Manager request from the Role approvals page.');

    Mail::assertSent(AdminBootstrapInvitation::class, fn ($mail): bool => $mail->hasTo('facility-hr@example.test'));
    $token = invitedFacilityAdminSetupToken();
    $this->post(route('admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongFacilityPassword!2026',
        'password_confirmation' => 'LongFacilityPassword!2026',
    ])->assertRedirect(route('login'));

    $target = User::query()->where('email', 'facility-hr@example.test')->firstOrFail();
    $promotion = TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('idn_admin_role_promotion_requests')
        ->where('target_user_id', $target->getKey())
        ->where('role_key', FacilityStaffManagerRoleDefinition::KEY)
        ->first());
    expect($promotion)->not->toBeNull()
        ->and($promotion->status)->toBe('PENDING')
        ->and($promotion->requested_by)->toBe($facilityAdmin->getKey());

    $this->actingAs($target)
        ->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->actingAs($facilityAdmin)
        ->get(route('admin.identity.role-promotions'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Identity/RolePromotions')
            ->where('promotions.0.roleKey', FacilityStaffManagerRoleDefinition::KEY)
            ->where('promotions.0.status', 'PENDING'));

    $this->actingAs($facilityAdmin)
        ->post(route('admin.identity.role-promotions.approve', $promotion->id))
        ->assertRedirect(route('admin.identity.role-promotions'));

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $target, $promotion, $facilityAdmin): void {
        $role = DB::table('idn_roles')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('key', FacilityStaffManagerRoleDefinition::KEY)
            ->first();
        $assignment = DB::table('idn_access_assignments')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('user_id', $target->getKey())
            ->where('role_id', $role->id)
            ->where('facility_id', $fixture['facility']->id)
            ->first();
        $permissions = DB::table('idn_permission_role as pr')
            ->join('idn_permissions as permission', 'permission.id', '=', 'pr.permission_id')
            ->where('pr.role_id', $role->id)
            ->pluck('permission.key')
            ->sort()
            ->values()
            ->all();

        expect($assignment)->not->toBeNull()
            ->and($assignment->status)->toBe('ACTIVE')
            ->and((bool) $role->is_protected)->toBeTrue()
            ->and($permissions)->toEqual(
                collect(FacilityStaffManagerRoleDefinition::PERMISSIONS)->sort()->values()->all(),
            )
            ->and(DB::table('idn_admin_role_promotion_requests')
                ->where('id', $promotion->id)
                ->value('request_origin'))->toBe('FACILITY_INVITATION')
            ->and(DB::table('idn_admin_role_promotion_requests')
                ->where('id', $promotion->id)
                ->value('decided_by'))->toBe($facilityAdmin->getKey())
            ->and(DB::table('shared_audit_log')
                ->where('tenant_id', $fixture['tenant']->id)
                ->where('facility_id', $fixture['facility']->id)
                ->where('action', 'identity.account.setup_completed')
                ->where('target_id', $target->getKey())
                ->exists())->toBeTrue();

        expect(DB::table('shared_audit_log')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('facility_id', $fixture['facility']->id)
            ->where('action', 'identity.admin_role_promotion.approved')
            ->where('actor_id', $facilityAdmin->getKey())
            ->where('target_id', $target->getKey())
            ->whereJsonContains('metadata->approval_mode', 'INVITING_FACILITY_ADMIN')
            ->exists())->toBeTrue();
    });
});

it('allows laboratory technician invitations only when laboratory is enabled at the facility', function (): void {
    $fixture = makeTenantWithUser();
    $tenantAdmin = createFacilityInvitationTenantAdmin($fixture);
    Mail::fake();
    $invitation = [
        'role_key' => 'laboratory_technician',
        'facility_id' => $fixture['facility']->id,
        'name' => 'Laboratory Technician',
        'email' => 'laboratory-technician@example.test',
        'change_reference' => 'LAB-INVITE-102',
        'reason' => 'Provide routine laboratory services.',
    ];

    $this->actingAs($tenantAdmin)
        ->post(route('admin.identity.role-promotions.facility-user-invitations.store'), $invitation)
        ->assertSessionHasErrors('email');

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture): void {
        DB::table('org_facility_capabilities')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $fixture['tenant']->id,
            'facility_id' => $fixture['facility']->id,
            'capability_code' => 'LABORATORY',
            'state' => 'ENABLED',
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($tenantAdmin)
        ->post(route('admin.identity.role-promotions.facility-user-invitations.store'), $invitation)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Setup invitation sent. After account setup, Laboratory Technician access will be active immediately if you hold identity-grant authority for the facility, otherwise approve the request from the linked staff record or Role approvals.');

    $roleId = TenantContextScope::run($fixture['tenant']->id, null, fn (): string => (string) DB::table('idn_roles')
        ->where('tenant_id', $fixture['tenant']->id)
        ->where('key', 'laboratory_technician')
        ->where('is_protected', true)
        ->value('id'));
    expect($roleId)->not->toBe('');
    $permissions = TenantContextScope::run($fixture['tenant']->id, null, fn (): array => DB::table('idn_permission_role as pr')
        ->join('idn_permissions as permission', 'permission.id', '=', 'pr.permission_id')
        ->where('pr.role_id', $roleId)
        ->orderBy('permission.key')
        ->pluck('permission.key')
        ->map(static fn ($key): string => (string) $key)
        ->all());
    expect($permissions)->toEqual([
        'laboratory.access',
        'laboratory.orders.accept',
        'laboratory.results.enter',
        'laboratory.results.verify',
        'laboratory.specimens.receive',
        'procurement.access',
        'procurement.requisitions.create',
    ]);
});

it('allows Clinician and Pharmacist invitations at a dispensary when Pharmacy is enabled', function (): void {
    $fixture = makeTenantWithUser();
    $tenantAdmin = createFacilityInvitationTenantAdmin($fixture);
    Mail::fake();
    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture): void {
        DB::table('org_facilities')
            ->where('id', $fixture['facility']->id)
            ->update(['facility_type' => 'DISPENSARY']);
        foreach ([
            ['doctor', 'Clinician'],
            ['pharmacist', 'Pharmacist'],
        ] as [$key, $name]) {
            DB::table('idn_roles')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $fixture['tenant']->id,
                'key' => $key,
                'name' => $name,
                'status' => 'ACTIVE',
                'scope_type' => 'FACILITY',
                'is_protected' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('org_facility_capabilities')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $fixture['tenant']->id,
            'facility_id' => $fixture['facility']->id,
            'capability_code' => 'PHARMACY',
            'state' => 'ENABLED',
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    foreach (['doctor', 'pharmacist'] as $roleKey) {
        $this->actingAs($tenantAdmin)
            ->post(route('admin.identity.role-promotions.facility-user-invitations.store'), [
                'role_key' => $roleKey,
                'facility_id' => $fixture['facility']->id,
                'name' => 'Eligible Dispensary Staff',
                'email' => "{$roleKey}@example.test",
                'change_reference' => 'DISPENSARY-ROLE-102',
                'reason' => 'Invite eligible staff to the dispensary.',
            ])
            ->assertSessionHasNoErrors();
    }

    Mail::assertSent(AdminBootstrapInvitation::class, 2);
});

it('resends an eligible expired facility invitation with a rotated setup link', function (): void {
    $fixture = makeTenantWithUser();
    $tenantAdmin = createFacilityInvitationTenantAdmin($fixture);
    createFacilityAdminTestRole($fixture['tenant']->id);
    $target = User::factory()->forTenant($fixture['tenant']->id)->create([
        'email' => 'expired.invite@example.test',
        'email_verified_at' => null,
        'status' => 'ACTIVE',
    ]);
    $invitationId = (string) Str::uuid7();
    $originalTokenHash = hash('sha256', 'expired-invitation-token');

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $tenantAdmin, $target, $invitationId, $originalTokenHash): void {
        DB::table('aut_admin_bootstrap_tokens')->insert([
            'id' => $invitationId,
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $target->getKey(),
            'token_hash' => $originalTokenHash,
            'created_by_reference' => 'OPS-INVITE-OLD',
            'purpose' => 'FACILITY_ROLE',
            'requested_by' => $tenantAdmin->getKey(),
            'facility_id' => $fixture['facility']->id,
            'role_key' => 'facility_admin',
            'role_keys' => json_encode(['facility_admin'], JSON_THROW_ON_ERROR),
            'expires_at' => now()->subMinute(),
            'consumed_at' => null,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
    });
    $this->actingAs($tenantAdmin)
        ->get(route('admin.identity.role-promotions'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('facilityInvitations.0.status', 'EXPIRED')
            ->where('facilityInvitations.0.canResend', true));
    Mail::fake();

    $this->actingAs($tenantAdmin)
        ->post(route('admin.identity.role-promotions.facility-user-invitations.resend', $invitationId), [
            'change_reference' => 'OPS-INVITE-RESEND',
            'reason' => 'The original setup link expired before use.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Setup invitation resent. The new link expires in 60 minutes.');

    Mail::assertSent(AdminBootstrapInvitation::class, fn ($mail): bool => $mail->hasTo('expired.invite@example.test'));
    $newToken = invitedFacilityAdminSetupToken();
    [$previousInvitation, $updatedInvitation] = TenantContextScope::run($fixture['tenant']->id, null, fn (): array => [
        DB::table('aut_admin_bootstrap_tokens')->where('id', $invitationId)->first(),
        DB::table('aut_admin_bootstrap_tokens')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('user_id', $target->getKey())
            ->where('facility_id', $fixture['facility']->id)
            ->where('purpose', 'FACILITY_ROLE')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(),
    ]);

    expect($previousInvitation->status)->toBe('SUPERSEDED')
        ->and($previousInvitation->token_hash)->toBe($originalTokenHash)
        ->and($updatedInvitation->id)->not->toBe($invitationId)
        ->and($updatedInvitation->token_hash)->toBe(hash('sha256', $newToken))
        ->and($updatedInvitation->created_by_reference)->toBe('OPS-INVITE-RESEND')
        ->and(now()->lt($updatedInvitation->expires_at))->toBeTrue()
        ->and($updatedInvitation->consumed_at)->toBeNull();
});

it('allows immediate audited recovery revocation for a sole Facility Admin', function (): void {
    $fixture = makeTenantWithUser();
    $platformAdmin = platformFacilityAdmin();
    $role = createFacilityAdminTestRole($fixture['tenant']->id);
    $target = User::factory()->forTenant($fixture['tenant']->id)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $assignmentId = (string) Str::uuid7();
    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $target, $role, $assignmentId): void {
        DB::table('idn_access_assignments')
            ->where('user_id', $fixture['user']->getKey())
            ->update(['status' => 'REVOKED', 'updated_at' => now()]);
        DB::table('idn_access_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $target->getKey(),
            'role_id' => $role->getKey(),
            'organization_id' => $fixture['organization']->id,
            'facility_id' => $fixture['facility']->id,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($platformAdmin)
        ->post(route('platform-admin.facilities.admin-revocations.store', [
            'tenantId' => $fixture['tenant']->id,
            'facilityId' => $fixture['facility']->id,
        ]), [
            'assignment_id' => $assignmentId,
            'reason' => 'Emergency sole-administrator recovery.',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Emergency sole-administrator revocation was applied immediately and audited.');

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $assignmentId, $platformAdmin): void {
        expect(DB::table('idn_access_assignments')->where('id', $assignmentId)->value('status'))->toBe('REVOKED')
            ->and(DB::table('idn_admin_role_promotion_requests')
                ->where('assignment_id', $assignmentId)
                ->exists())->toBeFalse()
            ->and(DB::table('shared_audit_log')
                ->where('tenant_id', $fixture['tenant']->id)
                ->where('actor_id', $platformAdmin->getKey())
                ->where('target_id', $assignmentId)
                ->where('action', 'identity.facility_admin.emergency_revoked')
                ->exists())->toBeTrue();
    });
});

it('allows platform super admins to emergency-revoke a Tenant Admin immediately even when another Tenant Admin exists', function (): void {
    $fixture = makeTenantWithUser();
    $platformAdmin = platformFacilityAdmin();
    $target = User::factory()->forTenant($fixture['tenant']->id)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $otherAdmin = User::factory()->forTenant($fixture['tenant']->id)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $role = TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture): Role {
        $role = new Role([
            'tenant_id' => $fixture['tenant']->id,
            'key' => 'tenant_admin',
            'name' => 'Tenant Admin',
            'status' => 'ACTIVE',
            'scope_type' => 'ORG',
        ]);
        $role->forceFill(['is_protected' => true])->save();

        return $role;
    });
    $assignmentId = (string) Str::uuid7();
    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $target, $otherAdmin, $role, $assignmentId): void {
        DB::table('idn_access_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $target->getKey(),
            'role_id' => $role->getKey(),
            'organization_id' => $fixture['organization']->id,
            'facility_id' => null,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $otherAdmin->getKey(),
            'role_id' => $role->getKey(),
            'organization_id' => $fixture['organization']->id,
            'facility_id' => null,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('aut_sessions')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $target->getKey(),
            'session_id' => 'tenant-admin-emergency-session',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'last_active_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($fixture['user'])
        ->post(route('platform-admin.tenant-admin-revocations.store', $fixture['tenant']->id), [
            'assignment_id' => $assignmentId,
            'reason' => 'Unauthorized emergency attempt.',
        ])
        ->assertForbidden();

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($assignmentId): void {
        expect(DB::table('idn_access_assignments')->where('id', $assignmentId)->value('status'))->toBe('ACTIVE');
    });

    $this->actingAs($platformAdmin)
        ->post(route('platform-admin.tenant-admin-revocations.store', $fixture['tenant']->id), [
            'assignment_id' => $assignmentId,
            'reason' => 'Emergency sole Tenant Admin recovery.',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Emergency Tenant Admin revocation was applied immediately and audited.');

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $assignmentId, $target, $otherAdmin, $role, $platformAdmin): void {
        expect(DB::table('idn_access_assignments')->where('id', $assignmentId)->value('status'))->toBe('REVOKED')
            ->and(DB::table('aut_sessions')->where('tenant_id', $fixture['tenant']->id)
                ->where('user_id', $target->getKey())->whereNull('revoked_at')->exists())->toBeFalse()
            ->and(DB::table('shared_audit_log')->where('tenant_id', $fixture['tenant']->id)
                ->where('actor_id', $platformAdmin->getKey())->where('target_id', $assignmentId)
                ->where('action', 'identity.tenant_admin.emergency_revoked')
                ->whereJsonContains('metadata->post_incident_review_required', true)->exists())->toBeTrue();
        expect(app(ScopeChecker::class)->effectiveOrganizationPermissions($target, (string) $fixture['organization']->id))
            ->toBe([]);
        expect(DB::table('idn_access_assignments')->where('tenant_id', $fixture['tenant']->id)
            ->where('user_id', $otherAdmin->getKey())->where('role_id', $role->getKey())
            ->where('status', 'ACTIVE')->exists())->toBeTrue();
        expect(DB::table('idn_emergency_access_reviews')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('assignment_id', $assignmentId)
            ->where('requested_by', $platformAdmin->getKey())
            ->where('status', 'PENDING')
            ->exists())->toBeTrue();
    });
});

it('requires a different platform super admin to complete an audited emergency revocation review', function (): void {
    $fixture = makeTenantWithUser();
    $initiator = platformFacilityAdmin();
    $reviewer = platformFacilityAdmin();
    $target = User::factory()->forTenant($fixture['tenant']->id)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $role = TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture): Role {
        $role = new Role([
            'tenant_id' => $fixture['tenant']->id,
            'key' => 'tenant_admin',
            'name' => 'Tenant Admin',
            'status' => 'ACTIVE',
            'scope_type' => 'ORG',
        ]);
        $role->forceFill(['is_protected' => true])->save();

        return $role;
    });
    $assignmentId = (string) Str::uuid7();
    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $target, $role, $assignmentId): void {
        DB::table('idn_access_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $target->getKey(),
            'role_id' => $role->getKey(),
            'organization_id' => $fixture['organization']->id,
            'facility_id' => null,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($initiator)
        ->post(route('platform-admin.tenant-admin-revocations.store', $fixture['tenant']->id), [
            'assignment_id' => $assignmentId,
            'reason' => 'Emergency security incident.',
        ])
        ->assertSessionHasNoErrors();

    $reviewId = TenantContextScope::run($fixture['tenant']->id, null, fn (): string => (string) DB::table('idn_emergency_access_reviews')
        ->where('tenant_id', $fixture['tenant']->id)
        ->where('assignment_id', $assignmentId)
        ->value('id')
    );

    $this->actingAs($initiator)
        ->post(route('platform-admin.tenant-admin-revocations.review', [
            'tenantId' => $fixture['tenant']->id,
            'reviewId' => $reviewId,
        ]), ['review_notes' => 'I reviewed the incident evidence.'])
        ->assertSessionHasErrors('review_notes');

    $this->actingAs($reviewer)
        ->post(route('platform-admin.tenant-admin-revocations.review', [
            'tenantId' => $fixture['tenant']->id,
            'reviewId' => $reviewId,
        ]), ['review_notes' => 'I reviewed the incident evidence.'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Emergency Tenant Admin revocation reviewed and audited.');

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $reviewId, $assignmentId, $initiator, $reviewer): void {
        expect(DB::table('idn_emergency_access_reviews')
            ->where('id', $reviewId)
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('assignment_id', $assignmentId)
            ->where('status', 'REVIEWED')
            ->where('requested_by', $initiator->getKey())
            ->where('reviewed_by', $reviewer->getKey())
            ->where('review_notes', 'I reviewed the incident evidence.')
            ->exists())->toBeTrue();
        expect(DB::table('shared_audit_log')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('actor_id', $reviewer->getKey())
            ->where('target_id', $assignmentId)
            ->where('action', 'identity.tenant_admin.emergency_revocation_reviewed')
            ->whereJsonContains('metadata->emergency_review', $reviewId)
            ->exists())->toBeTrue();
    });
});
