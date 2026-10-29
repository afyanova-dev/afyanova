<?php

declare(strict_types=1);

use App\Domains\Identity\Domain\Model\Role;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array{tenant: Tenant, organization: Organization, facility: Facility, requester: User, approver: User, target: User, adminRole: Role}
 */
function makeAdminPromotionFixture(): array
{
    $fixture = makeTenantWithUser([
        'admin.workspace.access',
        'audit.events.read',
        'identity.assignments.read',
        'identity.assignments.manage',
    ]);
    $tenant = $fixture['tenant'];
    $organization = $fixture['organization'];
    $facility = $fixture['facility'];
    $requester = $fixture['user'];
    $requester->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();
    $adminRole = TenantContextScope::run($tenant->id, null, function () use ($organization, $requester, $fixture): Role {
        $adminRole = Role::query()->findOrFail($fixture['role_id']);
        $adminRole->update(['scope_type' => 'ORG']);
        DB::table('idn_access_assignments')
            ->where('user_id', $requester->getKey())
            ->update([
                'facility_id' => null,
                'organization_id' => $organization->id,
            ]);

        return $adminRole;
    });

    $approver = User::factory()->forTenant($tenant->id)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $approver->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();
    $target = User::factory()->forTenant($tenant->id)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);

    TenantContextScope::run($tenant->id, null, function () use ($tenant, $organization, $requester, $approver, $adminRole): void {
        DB::table('idn_staff_identities')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'user_id' => $approver->getKey(),
            'is_staff' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$approver, $requester] as $user) {
            DB::table('idn_access_assignments')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'user_id' => $user->getKey(), 'role_id' => $adminRole->getKey()],
                [
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->id,
                    'facility_id' => null,
                    'status' => 'ACTIVE',
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    });

    TenantContextScope::run($tenant->id, null, function () use ($tenant): void {
        $roleId = (string) Str::uuid7();
        DB::table('idn_roles')->insert([
            'id' => $roleId,
            'tenant_id' => $tenant->id,
            'key' => 'tenant_admin',
            'name' => 'Tenant Admin',
            'status' => 'ACTIVE',
            'scope_type' => 'ORG',
            'is_protected' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['admin.workspace.access', 'audit.events.read', 'identity.assignments.read', 'identity.assignments.manage'] as $permissionKey) {
            $permissionId = DB::table('idn_permissions')->where('key', $permissionKey)->value('id');
            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant->id,
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    return [
        'tenant' => $tenant,
        'organization' => $organization,
        'facility' => $facility,
        'requester' => $requester,
        'approver' => $approver,
        'target' => $target,
        'adminRole' => $adminRole,
    ];
}

function submitTenantAdminPromotion(array $fixture): string
{
    $response = test()->actingAs($fixture['requester'])
        ->post(route('admin.identity.role-promotions.store'), [
            'role_key' => 'tenant_admin',
            'target_user_id' => $fixture['target']->id,
            'organization_id' => $fixture['organization']->id,
            'reason' => 'Assume organization administration duties.',
        ]);

    $response->assertRedirect(route('admin.identity.role-promotions'));

    return (string) DB::table('idn_admin_role_promotion_requests')
        ->where('target_user_id', $fixture['target']->id)
        ->value('id');
}

function createProtectedFacilityAdminRole(string $tenantId): Role
{
    return TenantContextScope::run($tenantId, null, function () use ($tenantId): Role {
        $role = new Role([
            'tenant_id' => $tenantId,
            'key' => 'facility_admin',
            'name' => 'Facility Admin',
            'status' => 'ACTIVE',
            'scope_type' => 'FACILITY',
        ]);
        $role->forceFill(['is_protected' => true])->save();

        return $role;
    });
}

it('searches only active verified tenant accounts for ordinary facility grants', function (): void {
    $fixture = makeAdminPromotionFixture();
    $fixture['target']->update([
        'name' => 'Needle Target',
        'email' => 'needle.target@example.test',
    ]);
    $ineligible = [
        User::factory()->forTenant($fixture['tenant']->id)->create([
            'name' => 'Needle Unverified',
            'email' => 'needle.unverified@example.test',
            'email_verified_at' => null,
            'status' => 'ACTIVE',
        ]),
        User::factory()->forTenant($fixture['tenant']->id)->create([
            'name' => 'Needle Inactive',
            'email' => 'needle.inactive@example.test',
            'email_verified_at' => now(),
            'status' => 'SUSPENDED',
        ]),
    ];
    $otherTenant = makeTenantWithUser();
    $otherTenant['user']->update([
        'name' => 'Needle Other Tenant',
        'email' => 'needle.other-tenant@example.test',
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    foreach (range(1, 22) as $index) {
        User::factory()->forTenant($fixture['tenant']->id)->create([
            'name' => sprintf('Needle User %02d', $index),
            'email' => sprintf('needle.user%02d@example.test', $index),
            'email_verified_at' => now(),
            'status' => 'ACTIVE',
        ]);
    }

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $ineligible, $otherTenant): void {
        $response = $this->actingAs($fixture['requester'])
            ->getJson(route('admin.identity.facility-assignment-targets', [
                'facility_id' => $fixture['facility']->id,
                'search' => 'needle',
            ]))
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'email']]]);

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain($fixture['target']->id)
            ->not->toContain($ineligible[0]->id)
            ->not->toContain($ineligible[1]->id)
            ->not->toContain($otherTenant['user']->id);
    });
});

it('denies ordinary grant account search outside the administrator facility scope', function (): void {
    $fixture = makeAdminPromotionFixture();

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        $this->actingAs($fixture['target'])
            ->getJson(route('admin.identity.facility-assignment-targets', [
                'facility_id' => $fixture['facility']->id,
                'search' => 'target',
            ]))
            ->assertForbidden();
    });
});

it('requires a distinct authorized administrator to approve and activates only after approval', function (): void {
    $fixture = makeAdminPromotionFixture();
    $requestId = TenantContextScope::run(
        $fixture['tenant']->id,
        $fixture['facility']->id,
        fn (): string => submitTenantAdminPromotion($fixture),
    );

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        $this->actingAs($fixture['requester'])
            ->get(route('admin.identity.role-promotions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Identity/RolePromotions')
                ->has('promotions', 1)
                ->where('promotions.0.status', 'PENDING')
                ->where('promotions.0.targetName', $fixture['target']->name)
                ->where('promotions.0.targetEmail', $fixture['target']->email)
                ->where('promotions.0.requestedByName', $fixture['requester']->name)
                ->where('promotions.0.scopeName', $fixture['organization']->name)
                ->where('total', 1)
                ->where('currentPage', 1)
                ->where('lastPage', 1));
    });

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $requestId): void {
        expect(DB::table('idn_access_assignments')
            ->where('user_id', $fixture['target']->id)
            ->where('role_id', DB::table('idn_roles')->where('tenant_id', $fixture['tenant']->id)->where('key', 'tenant_admin')->value('id'))
            ->exists())->toBeFalse();

        $this->actingAs($fixture['requester'])
            ->post(route('admin.identity.role-promotions.approve', $requestId))
            ->assertForbidden();

        $this->actingAs($fixture['approver'])
            ->post(route('admin.identity.role-promotions.approve', $requestId))
            ->assertRedirect(route('admin.identity.role-promotions'));

        $this->actingAs($fixture['approver'])
            ->post(route('admin.identity.role-promotions.approve', $requestId))
            ->assertStatus(409);

        $this->actingAs($fixture['approver'])
            ->get(route('admin.identity.role-promotions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('promotions', 1)
                ->where('statusFilter', 'ALL')
                ->where('total', 1)
                ->where('statusCounts.PENDING', 0)
                ->where('statusCounts.APPROVED', 1)
                ->where('statusCounts.ALL', 1));

        $this->actingAs($fixture['approver'])
            ->get(route('admin.identity.role-promotions', ['status' => 'APPROVED']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('promotions', 1)
                ->where('promotions.0.status', 'APPROVED')
                ->where('statusFilter', 'APPROVED')
                ->where('total', 1)
                ->where('statusCounts.PENDING', 0)
                ->where('statusCounts.APPROVED', 1)
                ->where('statusCounts.ALL', 1));
    });

    it('approves a protected Facility Admin assignment only at the requested facility', function (): void {
        $fixture = makeAdminPromotionFixture();
        $role = createProtectedFacilityAdminRole($fixture['tenant']->id);

        TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
            $this->actingAs($fixture['requester'])
                ->post(route('admin.identity.role-promotions.store'), [
                    'role_key' => 'facility_admin',
                    'target_user_id' => $fixture['target']->id,
                    'facility_id' => $fixture['facility']->id,
                    'reason' => 'Assume facility administration duties.',
                ])
                ->assertRedirect(route('admin.identity.role-promotions'));
        });

        $requestId = TenantContextScope::run($fixture['tenant']->id, null, fn (): string => (string) DB::table('idn_admin_role_promotion_requests')
            ->where('target_user_id', $fixture['target']->id)
            ->where('role_id', $role->getKey())
            ->value('id'));

        TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $requestId): void {
            $this->actingAs($fixture['approver'])
                ->post(route('admin.identity.role-promotions.approve', $requestId))
                ->assertRedirect(route('admin.identity.role-promotions'));
        });

        $assignment = TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('idn_access_assignments')
            ->where('user_id', $fixture['target']->id)
            ->where('role_id', $role->getKey())
            ->first());

        expect($assignment->organization_id)->toBe($fixture['organization']->id)
            ->and($assignment->facility_id)->toBe($fixture['facility']->id)
            ->and($assignment->granted_by)->toBe($fixture['approver']->id);
    });

    $assignment = TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('idn_access_assignments')
        ->where('user_id', $fixture['target']->id)
        ->where('organization_id', $fixture['organization']->id)
        ->where('status', 'ACTIVE')
        ->first());
    $requestRow = TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('idn_admin_role_promotion_requests')
        ->where('id', $requestId)
        ->first());
    $events = TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('shared_audit_log')
        ->where('target_id', $fixture['target']->id)
        ->whereIn('action', [
            'identity.admin_role_promotion.requested',
            'identity.admin_role_promotion.approved',
        ])
        ->get());

    expect($assignment)->not->toBeNull()
        ->and($assignment->granted_by)->toBe($fixture['approver']->id)
        ->and($requestRow->status)->toBe('APPROVED')
        ->and($requestRow->requested_by)->toBe($fixture['requester']->id)
        ->and($requestRow->decided_by)->toBe($fixture['approver']->id)
        ->and($requestRow->assignment_id)->toBe($assignment->id)
        ->and($events)->toHaveCount(2);
});

it('returns a decision to the approvals page the actor was viewing', function (): void {
    $fixture = makeAdminPromotionFixture();
    $requestId = TenantContextScope::run(
        $fixture['tenant']->id,
        $fixture['facility']->id,
        fn (): string => submitTenantAdminPromotion($fixture),
    );
    $referer = route('admin.identity.role-promotions', [
        'status' => 'PENDING',
        'page' => 1,
        'tab' => 'requests',
    ]);

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $requestId, $referer): void {
        $this->actingAs($fixture['approver'])
            ->from($referer)
            ->post(route('admin.identity.role-promotions.approve', $requestId))
            ->assertRedirect($referer);
    });
});

it('lists facility invitations only as safe, scoped account details', function (): void {
    $fixture = makeAdminPromotionFixture();
    $tokenHash = hash('sha256', 'private-invitation-secret');
    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $tokenHash): void {
        DB::table('aut_admin_bootstrap_tokens')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $fixture['target']->id,
            'token_hash' => $tokenHash,
            'created_by_reference' => 'TEST-INVITATION',
            'purpose' => 'FACILITY_ROLE',
            'requested_by' => $fixture['requester']->id,
            'facility_id' => $fixture['facility']->id,
            'role_key' => 'nurse',
            'role_keys' => json_encode(['nurse', 'receptionist'], JSON_THROW_ON_ERROR),
            'expires_at' => now()->addMinutes(30),
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $tokenHash): void {
        $response = $this->actingAs($fixture['requester'])
            ->get(route('admin.identity.role-promotions'))
            ->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Identity/RolePromotions')
            ->has('facilityInvitations', 1)
            ->where('facilityInvitations.0.userName', $fixture['target']->name)
            ->where('facilityInvitations.0.userEmail', $fixture['target']->email)
            ->where('facilityInvitations.0.roles', ['Nurse', 'Receptionist'])
            ->where('facilityInvitations.0.facilityName', $fixture['facility']->name)
            ->where('facilityInvitations.0.status', 'AWAITING_SETUP')
            ->where('facilityInvitations.0.canResend', false)
            ->missing('facilityInvitations.0.tokenHash')
            ->missing('facilityInvitations.0.token_hash'));
        $response->assertDontSee($tokenHash);
    });
});

it('accepts an account email when requesting an administrative role', function (): void {
    $fixture = makeAdminPromotionFixture();

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        $this->actingAs($fixture['requester'])
            ->post(route('admin.identity.role-promotions.store'), [
                'role_key' => 'tenant_admin',
                'target_email' => $fixture['target']->email,
                'organization_id' => $fixture['organization']->id,
                'reason' => 'Assume organization administration duties.',
            ])
            ->assertRedirect(route('admin.identity.role-promotions'));
    });

    expect(DB::table('idn_admin_role_promotion_requests')
        ->where('tenant_id', $fixture['tenant']->id)
        ->where('target_user_id', $fixture['target']->id)
        ->where('role_key', 'tenant_admin')
        ->exists())->toBeTrue();
});

it('resolves an active platform administrator as the requester without widening tenant user lookup', function (): void {
    $fixture = makeAdminPromotionFixture();
    $requestId = TenantContextScope::run(
        $fixture['tenant']->id,
        $fixture['facility']->id,
        fn (): string => submitTenantAdminPromotion($fixture),
    );
    $platformRequester = User::factory()->create([
        'tenant_id' => null,
        'name' => 'Platform Operator',
        'email' => 'platform.operator@example.test',
    ]);

    DB::table('platform_super_admins')->insert([
        'id' => (string) Str::uuid7(),
        'user_id' => $platformRequester->getKey(),
        'status' => 'ACTIVE',
        'created_by_reference' => 'test-fixture',
        'activated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use (
        $fixture,
        $requestId,
        $platformRequester,
    ): void {
        DB::table('idn_admin_role_promotion_requests')
            ->where('id', $requestId)
            ->update(['requested_by' => $platformRequester->getKey()]);

        $this->actingAs($fixture['requester'])
            ->get(route('admin.identity.role-promotions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('promotions.0.requestedByName', 'Platform Operator')
                ->where('promotions.0.requestedByEmail', 'platform.operator@example.test'));
    });
});

it('grants staff-management capabilities to an existing user at only the selected facility', function (): void {
    $fixture = makeAdminPromotionFixture();
    $secondFacility = TenantContextScope::run(
        $fixture['tenant']->id,
        null,
        fn (): Facility => Facility::factory()->forTenant($fixture['tenant']->id, $fixture['organization']->id)->create(),
    );
    $ordinaryRole = TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture): Role {
        $role = new Role([
            'tenant_id' => $fixture['tenant']->id,
            'key' => 'facility_user',
            'name' => 'Facility User',
            'status' => 'ACTIVE',
            'scope_type' => 'FACILITY',
        ]);
        $role->save();

        return $role;
    });
    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $secondFacility, $ordinaryRole): void {
        foreach ([$fixture['facility'], $secondFacility] as $facility) {
            DB::table('idn_access_assignments')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $fixture['tenant']->id,
                'user_id' => $fixture['target']->getKey(),
                'role_id' => $ordinaryRole->getKey(),
                'organization_id' => $fixture['organization']->id,
                'facility_id' => $facility->getKey(),
                'status' => 'ACTIVE',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $secondFacility): void {
        $this->actingAs($fixture['requester'])
            ->post(route('admin.identity.facility-staff-managers.store'), [
                'facility_id' => $secondFacility->getKey(),
                'email' => $fixture['target']->email,
            ])
            ->assertRedirect(route('admin.identity.role-promotions'));
    });

    $assignment = TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('idn_access_assignments as assignment')
        ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
        ->where('assignment.tenant_id', $fixture['tenant']->id)
        ->where('assignment.user_id', $fixture['target']->getKey())
        ->where('role.key', 'facility_staff_manager')
        ->where('assignment.status', 'ACTIVE')
        ->select('assignment.id', 'assignment.facility_id as assigned_facility_id')
        ->sole());
    expect($assignment->assigned_facility_id)->toBe($secondFacility->getKey());

    $permissionsAtSelectedFacility = TenantContextScope::run(
        $fixture['tenant']->id,
        $secondFacility->getKey(),
        fn (): array => app(ScopeChecker::class)
            ->effectivePermissions($fixture['target'], $secondFacility->getKey()),
    );
    $permissionsAtOtherFacility = TenantContextScope::run(
        $fixture['tenant']->id,
        $fixture['facility']->getKey(),
        fn (): array => app(ScopeChecker::class)
            ->effectivePermissions($fixture['target'], $fixture['facility']->getKey()),
    );
    expect($permissionsAtSelectedFacility)->toContain('hr.staff.manage')
        ->and($permissionsAtSelectedFacility)->toContain('hr.employment.manage')
        ->and($permissionsAtOtherFacility)->not->toContain('hr.staff.manage');

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $assignment): void {
        $this->actingAs($fixture['requester'])
            ->delete(route('admin.identity.facility-staff-managers.destroy', $assignment->id))
            ->assertRedirect(route('admin.identity.role-promotions'));
    });
    expect(TenantContextScope::run(
        $fixture['tenant']->id,
        null,
        fn (): string => (string) DB::table('idn_access_assignments')->where('id', $assignment->id)->value('status'),
    ))->toBe('REVOKED');
    $permissionsAfterRevoke = TenantContextScope::run(
        $fixture['tenant']->id,
        $secondFacility->getKey(),
        fn (): array => app(ScopeChecker::class)
            ->effectivePermissions($fixture['target'], $secondFacility->getKey()),
    );
    expect($permissionsAfterRevoke)->not->toContain('hr.staff.manage');
});

it('prevents a Facility Admin from granting staff-management access outside their facility', function (): void {
    $fixture = makeAdminPromotionFixture();
    $otherFacility = TenantContextScope::run(
        $fixture['tenant']->id,
        null,
        fn (): Facility => Facility::factory()->forTenant($fixture['tenant']->id, $fixture['organization']->id)->create(),
    );
    $facilityAdminRole = createProtectedFacilityAdminRole($fixture['tenant']->id);
    $ordinaryRole = TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture): Role {
        $role = new Role([
            'tenant_id' => $fixture['tenant']->id,
            'key' => 'facility_user',
            'name' => 'Facility User',
            'status' => 'ACTIVE',
            'scope_type' => 'FACILITY',
        ]);
        $role->save();

        return $role;
    });

    TenantContextScope::run($fixture['tenant']->id, null, function () use (
        $fixture,
        $facilityAdminRole,
        $ordinaryRole,
        $otherFacility,
    ): void {
        DB::table('idn_access_assignments')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('user_id', $fixture['requester']->getKey())
            ->update(['status' => 'REVOKED']);
        foreach (['admin.workspace.access', 'identity.assignments.manage'] as $permissionKey) {
            $permissionId = DB::table('idn_permissions')->where('key', $permissionKey)->value('id');
            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $fixture['tenant']->id,
                'role_id' => $facilityAdminRole->getKey(),
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $fixture['requester']->getKey(),
            'role_id' => $facilityAdminRole->getKey(),
            'organization_id' => $fixture['organization']->id,
            'facility_id' => $fixture['facility']->getKey(),
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $fixture['target']->getKey(),
            'role_id' => $ordinaryRole->getKey(),
            'organization_id' => $fixture['organization']->id,
            'facility_id' => $otherFacility->getKey(),
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $otherFacility): void {
        $this->actingAs($fixture['requester'])
            ->post(route('admin.identity.facility-staff-managers.store'), [
                'facility_id' => $otherFacility->getKey(),
                'email' => $fixture['target']->email,
            ])
            ->assertForbidden();
    });
});

it('limits Facility Admin promotion requests and decisions to the assigned facility', function (): void {
    $fixture = makeTenantWithUser([
        'admin.workspace.access',
        'identity.assignments.read',
        'identity.assignments.manage',
    ]);
    $requester = $fixture['user'];
    $requester->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();

    $facilityAdminRole = TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture): Role {
        $role = Role::query()->findOrFail($fixture['role_id']);
        $role->update(['key' => 'facility_admin']);
        $role->forceFill(['is_protected' => true])->save();

        return $role;
    });
    $otherFacility = TenantContextScope::run(
        $fixture['tenant']->id,
        null,
        fn (): Facility => Facility::factory()->forTenant($fixture['tenant']->id, $fixture['organization']->id)->create(),
    );
    $otherFacilityAdmin = User::factory()->forTenant($fixture['tenant']->id)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $otherFacilityAdmin->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();
    $target = User::factory()->forTenant($fixture['tenant']->id)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $otherFacility, $otherFacilityAdmin, $facilityAdminRole): void {
        DB::table('idn_staff_identities')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $otherFacilityAdmin->getKey(),
            'is_staff' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $otherFacilityAdmin->getKey(),
            'role_id' => $facilityAdminRole->getKey(),
            'facility_id' => $otherFacility->id,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $target): void {
        $this->actingAs($fixture['user'])
            ->post(route('admin.identity.role-promotions.store'), [
                'role_key' => 'facility_admin',
                'target_user_id' => $target->id,
                'facility_id' => $fixture['facility']->id,
                'reason' => 'Assume facility administration duties.',
            ])
            ->assertRedirect(route('admin.identity.role-promotions'));
    });

    $requestId = TenantContextScope::run($fixture['tenant']->id, null, fn (): string => (string) DB::table('idn_admin_role_promotion_requests')
        ->where('target_user_id', $target->id)
        ->value('id'));

    TenantContextScope::run($fixture['tenant']->id, $otherFacility->id, function () use ($otherFacilityAdmin, $requestId): void {
        $this->actingAs($otherFacilityAdmin)
            ->get(route('admin.identity.role-promotions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('promotions', 0));

        $this->actingAs($otherFacilityAdmin)
            ->post(route('admin.identity.role-promotions.approve', $requestId))
            ->assertForbidden();
    });
});

it('does not authorize a request to become or approve oneself', function (): void {
    $fixture = makeAdminPromotionFixture();

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        $this->actingAs($fixture['requester'])
            ->post(route('admin.identity.role-promotions.store'), [
                'role_key' => 'tenant_admin',
                'target_user_id' => $fixture['requester']->id,
                'organization_id' => $fixture['organization']->id,
                'reason' => 'Self promotion must be prevented.',
            ])
            ->assertUnprocessable();
    });
});

it('records rejection separately and creates no administrative assignment', function (): void {
    $fixture = makeAdminPromotionFixture();
    $requestId = TenantContextScope::run(
        $fixture['tenant']->id,
        $fixture['facility']->id,
        fn (): string => submitTenantAdminPromotion($fixture),
    );

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $requestId): void {
        $this->actingAs($fixture['approver'])
            ->post(route('admin.identity.role-promotions.reject', $requestId), [
                'decision_reason' => 'The target needs additional role training.',
            ])
            ->assertRedirect(route('admin.identity.role-promotions'));
    });

    $requestRow = TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('idn_admin_role_promotion_requests')
        ->where('id', $requestId)
        ->first());
    $rejectionAudit = TenantContextScope::run($fixture['tenant']->id, null, fn () => DB::table('shared_audit_log')
        ->where('action', 'identity.admin_role_promotion.rejected')
        ->where('target_id', $fixture['target']->id)
        ->first());

    expect($requestRow->status)->toBe('REJECTED')
        ->and($rejectionAudit)->not->toBeNull()
        ->and($rejectionAudit->actor_id)->toBe($fixture['approver']->id)
        ->and(DB::table('idn_access_assignments')
            ->where('user_id', $fixture['target']->id)
            ->where('organization_id', $fixture['organization']->id)
            ->exists())->toBeFalse();
});
