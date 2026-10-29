<?php

declare(strict_types=1);

use App\Domains\Authentication\Mail\AdminBootstrapInvitation;
use App\Domains\Authentication\Mail\FacilityInvitationExpired;
use App\Domains\HR\Application\Command\CreateStaff;
use App\Domains\HR\Application\Command\EndAssignment;
use App\Domains\HR\Application\Command\OnboardStaff;
use App\Domains\Identity\Contracts\BillingAdminRoleDefinition;
use App\Domains\Identity\Contracts\CashierRoleDefinition;
use App\Domains\Identity\Contracts\ClinicianRoleDefinition;
use App\Domains\Identity\Contracts\NurseRoleDefinition;
use App\Domains\Identity\Contracts\PharmacistRoleDefinition;
use App\Domains\Identity\Contracts\ReceptionistRoleDefinition;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../tests/Support/hr.php';

/**
 * Helpers local to this file (prefixed to avoid collisions).
 */
function sacEnsureNurseRole(string $tenantId): string
{
    return TenantContextScope::run($tenantId, null, function () use ($tenantId): string {
        $existing = DB::table('idn_roles')->where('tenant_id', $tenantId)->where('key', 'nurse')->value('id');
        if (is_string($existing)) {
            return $existing;
        }
        $roleId = (string) Str::uuid7();
        DB::table('idn_roles')->insert([
            'id' => $roleId,
            'tenant_id' => $tenantId,
            'key' => 'nurse',
            'name' => 'Nurse',
            'status' => 'ACTIVE',
            'scope_type' => 'FACILITY',
            'is_protected' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permId = DB::table('idn_permissions')->where('key', 'patient.patients.read')->value('id');
        if (! is_string($permId)) {
            $permId = (string) Str::uuid7();
            DB::table('idn_permissions')->insert([
                'id' => $permId, 'key' => 'patient.patients.read', 'layer' => 'ACTION',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('idn_permission_role')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'role_id' => $roleId, 'permission_id' => $permId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $roleId;
    });
}

function sacEnsureReceptionistRole(string $tenantId): string
{
    return TenantContextScope::run($tenantId, null, function () use ($tenantId): string {
        $existing = DB::table('idn_roles')->where('tenant_id', $tenantId)->where('key', 'receptionist')->value('id');
        if (is_string($existing)) {
            return $existing;
        }
        $roleId = (string) Str::uuid7();
        DB::table('idn_roles')->insert([
            'id' => $roleId, 'tenant_id' => $tenantId, 'key' => 'receptionist',
            'name' => 'Receptionist', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY',
            'is_protected' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $roleId;
    });
}

function sacMakeIdentityAdmin(string $tenantId, string $facilityId, string $email): User
{
    $user = User::factory()->forTenant($tenantId)->create([
        'email' => $email,
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ]);
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $user): void {
        $roleId = DB::table('idn_roles')->where('tenant_id', $tenantId)->where('key', 'test_identity_admin')->value('id');
        if (! is_string($roleId)) {
            $roleId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $roleId, 'tenant_id' => $tenantId, 'key' => 'test_identity_admin',
                'name' => 'Test Identity Admin', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['admin.workspace.access', 'identity.assignments.manage', 'identity.assignments.read'] as $key) {
                $permId = DB::table('idn_permissions')->where('key', $key)->value('id');
                if (! is_string($permId)) {
                    $permId = (string) Str::uuid7();
                    DB::table('idn_permissions')->insert([
                        'id' => $permId, 'key' => $key,
                        'layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('idn_permission_role')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
                    'role_id' => $roleId, 'permission_id' => $permId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'user_id' => $user->getKey(), 'role_id' => $roleId, 'facility_id' => $facilityId,
            'status' => 'ACTIVE', 'is_primary' => true, 'granted_by' => $user->getKey(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    return $user;
}

function sacMakeHrOnly(string $tenantId, string $facilityId, string $email): User
{
    $user = User::factory()->forTenant($tenantId)->create([
        'email' => $email,
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $user): void {
        $roleId = DB::table('idn_roles')->where('tenant_id', $tenantId)->where('key', 'test_hr_only')->value('id');
        if (! is_string($roleId)) {
            $roleId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $roleId, 'tenant_id' => $tenantId, 'key' => 'test_hr_only',
                'name' => 'Test HR', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['hr.person.manage', 'hr.staff.manage', 'hr.assignment.manage', 'hr.staff_user_link.manage'] as $key) {
                $permId = DB::table('idn_permissions')->where('key', $key)->value('id');
                if (! is_string($permId)) {
                    $permId = (string) Str::uuid7();
                    DB::table('idn_permissions')->insert([
                        'id' => $permId, 'key' => $key, 'layer' => 'ACTION',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('idn_permission_role')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
                    'role_id' => $roleId, 'permission_id' => $permId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'user_id' => $user->getKey(), 'role_id' => $roleId, 'facility_id' => $facilityId,
            'status' => 'ACTIVE', 'is_primary' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    return $user;
}

function sacSetupToken(): string
{
    $mail = Mail::sent(AdminBootstrapInvitation::class)->last();
    expect($mail)->toBeInstanceOf(AdminBootstrapInvitation::class);
    preg_match('/#([A-Za-z0-9]{64})/', $mail->render(), $m);
    expect($m[1] ?? null)->toBeString();

    return $m[1];
}

function sacSelectFacility(object $test, string $facilityId): void
{
    $test->post(route('facility-context.update'), ['facility_id' => $facilityId])->assertRedirect();
}

function sacCreateInvitableStaff(
    string $tenantId,
    string $facilityId,
    string $firstName = 'Nuru',
    string $lastName = 'Nurse',
): string {
    $staffId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $staffId, $firstName, $lastName): void {
        $personId = createPersonHelper(
            $tenantId,
            $firstName,
            null,
            $lastName,
            facilityId: $facilityId,
        );
        app(CommandBus::class)->dispatch(new CreateStaff(personId: $personId, staffId: $staffId));
        DB::table('hr_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'facility_id' => $facilityId,
            'scope' => 'FACILITY',
            'effective_from' => now()->toDateString(),
            'is_primary' => true,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return $staffId;
}

it('grants an ordinary role directly when a Facility Admin invites within scope', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access', 'identity.assignments.manage', 'identity.assignments.read']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacEnsureNurseRole($tenantId);
    $fixture['user']->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    Mail::fake();

    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.role-promotions.facility-user-invitations.store'), [
            'role_key' => 'nurse',
            'facility_id' => $facilityId,
            'name' => 'Nuru Nurse',
            'email' => 'nuru.direct@example.test',
            'change_reference' => 'CHG-001',
            'reason' => 'Provide nursing care at this facility.',
        ])->assertSessionHasNoErrors();

    $token = sacSetupToken();
    $this->post(route('admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongNursePassword!2026',
        'password_confirmation' => 'LongNursePassword!2026',
    ])->assertRedirect(route('login'));

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId): void {
        $target = User::query()->where('email', 'nuru.direct@example.test')->firstOrFail();
        $assignment = DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)->where('user_id', $target->getKey())
            ->where('facility_id', $facilityId)->where('status', 'ACTIVE')->first();
        expect($assignment)->not->toBeNull();
        expect((bool) $assignment->is_primary)->toBeTrue();
        // No promotion row for direct grants.
        expect(DB::table('idn_admin_role_promotion_requests')
            ->where('tenant_id', $tenantId)->where('target_user_id', $target->getKey())->exists())->toBeFalse();
        expect(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
            ->where('action', 'identity.facility_user.granted')->where('target_id', $target->getKey())->exists())->toBeTrue();
        expect(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
            ->where('action', 'identity.account.setup_completed')->where('target_id', $target->getKey())->exists())->toBeTrue();
    });
});

it('grants an ordinary role directly for a Tenant Admin within tenant scope', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access', 'identity.assignments.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacEnsureNurseRole($tenantId);
    // Promote fixture user to ORG-scoped tenant admin.
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $fixture): void {
        DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $fixture['user']->getKey())
            ->update(['facility_id' => null, 'organization_id' => $fixture['organization']->id]);
        DB::table('idn_roles')->where('id', $fixture['role_id'])->update(['scope_type' => 'ORG']);
    });
    $fixture['user']->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    Mail::fake();

    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.role-promotions.facility-user-invitations.store'), [
            'role_key' => 'nurse',
            'facility_id' => $facilityId,
            'name' => 'Taji Nurse',
            'email' => 'taji.direct@example.test',
            'change_reference' => 'CHG-002',
            'reason' => 'Provide nursing care at this facility.',
        ])->assertSessionHasNoErrors();

    $token = sacSetupToken();
    $this->post(route('admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongNursePassword!2026',
        'password_confirmation' => 'LongNursePassword!2026',
    ])->assertRedirect(route('login'));

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId): void {
        $target = User::query()->where('email', 'taji.direct@example.test')->firstOrFail();
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $target->getKey())->where('facility_id', $facilityId)
            ->where('status', 'ACTIVE')->exists())->toBeTrue();
    });
});

it('rejects ordinary grants from unauthorized actors', function (): void {
    // Workspace access but neither identity-grant nor HR authority.
    $fixture = makeTenantWithUser(['admin.workspace.access']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacEnsureNurseRole($tenantId);
    $fixture['user']->forceFill([
        'two_factor_secret' => encrypt('x'), 'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    Mail::fake();

    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.role-promotions.facility-user-invitations.store'), [
            'role_key' => 'nurse',
            'facility_id' => $facilityId,
            'name' => 'No Perm',
            'email' => 'noperm@example.test',
            'change_reference' => 'CHG-003',
            'reason' => 'Provide nursing care at this facility.',
        ])->assertSessionHasErrors('email');
});

it('lets an HR manager approve each pending role from their own onboarding invitation', function (): void {
    // HR initiates via the HR staff workflow (hr.staff.store with login),
    // not the admin invite endpoint (which requires admin workspace access).
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacSelectFacility($this, $facilityId);
    sacEnsureNurseRole($tenantId);
    sacEnsureReceptionistRole($tenantId);
    Mail::fake();

    // HR-only actor (fixture user has hr.* but no identity-grant authority
    // after we strip it): use a dedicated HR-only user for clarity.
    $hr = sacMakeHrOnly($tenantId, $facilityId, 'hr.only2@example.test');
    sacSelectFacility($this, $facilityId);

    $this->actingAs($hr)
        ->post(route('hr.staff.store'), [
            'first_name' => 'Too',
            'last_name' => 'Many',
            'date_of_birth' => '1990-04-12',
            'gender' => 'FEMALE',
            'email' => 'too.many.roles@example.test',
            'request_login' => true,
            'primary_role_key' => 'nurse',
            'secondary_role_keys' => ['receptionist', 'doctor', 'pharmacist'],
            'change_reference' => 'CHG-TOO-MANY',
            'access_reason' => 'Validate the secondary role limit.',
            'effective_from' => now()->toDateString(),
        ])->assertSessionHasErrors('secondary_role_keys');

    $this->actingAs($hr)
        ->post(route('hr.staff.store'), [
            'first_name' => 'Hr',
            'last_name' => 'Nurse',
            'date_of_birth' => '1990-04-12',
            'gender' => 'FEMALE',
            'email' => 'hr.nurse@example.test',
            'request_login' => true,
            'primary_role_key' => 'nurse',
            'secondary_role_keys' => ['receptionist'],
            'change_reference' => 'CHG-004',
            'access_reason' => 'Provide nursing care at this facility.',
            'effective_from' => now()->toDateString(),
        ])->assertRedirect(route('hr.staff.show', [
            'staff' => DB::table('hr_staff as staff')
                ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
                ->where('person.email', 'hr.nurse@example.test')
                ->value('staff.id'),
            'section' => 'access',
        ]));

    // Invitation was sent via HR workflow.
    $token = sacSetupToken();
    $this->post(route('admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongNursePassword!2026',
        'password_confirmation' => 'LongNursePassword!2026',
    ])->assertRedirect(route('login'))
        ->assertSessionHas(
            'status',
            'Account setup is complete. The administrator who invited you must approve your access before you can sign in.',
        );

    $target = User::query()->where('email', 'hr.nurse@example.test')->firstOrFail();
    $staffId = (string) DB::table('hr_staff as staff')
        ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
        ->where('person.email', 'hr.nurse@example.test')
        ->value('staff.id');
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $target): void {
        // No ACTIVE assignment: pending promotion instead.
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $target->getKey())->where('status', 'ACTIVE')->exists())->toBeFalse();
        $promotion = DB::table('idn_admin_role_promotion_requests')
            ->where('tenant_id', $tenantId)->where('target_user_id', $target->getKey())
            ->where('role_key', 'nurse')->where('status', 'PENDING')->first();
        expect($promotion)->not->toBeNull();
        expect((bool) $promotion->is_primary)->toBeTrue();
        $secondary = DB::table('idn_admin_role_promotion_requests')
            ->where('tenant_id', $tenantId)->where('target_user_id', $target->getKey())
            ->where('role_key', 'receptionist')->where('status', 'PENDING')->first();
        expect($secondary)->not->toBeNull();
        expect((bool) $secondary->is_primary)->toBeFalse();
    });

    // The HR inviter reviews and approves each pending role from the access request worklist.
    $promotionRequests = TenantContextScope::run($tenantId, null, fn () => DB::table('idn_admin_role_promotion_requests')
        ->where('tenant_id', $tenantId)->where('target_user_id', $target->getKey())
        ->orderBy('role_key')->get(['id', 'role_key'])->all());
    $this->actingAs($hr)
        ->get(route('hr.staff.access-requests'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/AccessRequests')
            ->where('timezone', 'Africa/Dar_es_Salaam')
            ->has('requests', 2)
            ->where('requests.0.staffId', $staffId)
            ->where('requests.0.accountEmail', 'hr.nurse@example.test')
            ->where('requests.0.requestedAt', fn ($value) => is_string($value)
                && str_contains($value, 'T')
                && preg_match('/[+-]\d{2}:\d{2}$/', $value) === 1));
    foreach ($promotionRequests as $promotionRequest) {
        if ($promotionRequest->role_key === 'receptionist') {
            $this->actingAs($hr)
                ->post(route('hr.staff.access-requests.reject', [
                    'promotion' => $promotionRequest->id,
                    'staff' => $staffId,
                ]), [
                    'decision_reason' => 'The reception role is no longer required.',
                ])
                ->assertRedirect(route('hr.staff.access-requests'));

            continue;
        }

        $this->actingAs($hr)
            ->post(route('hr.staff.access-requests.approve', [
                'promotion' => $promotionRequest->id,
                'staff' => $staffId,
            ]), ['return_to' => 'access-requests'])
            ->assertRedirect(route('hr.staff.access-requests'));
    }

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $target, $facilityId): void {
        $assignments = DB::table('idn_access_assignments')
            ->join('idn_roles', 'idn_roles.id', '=', 'idn_access_assignments.role_id')
            ->where('idn_access_assignments.tenant_id', $tenantId)
            ->where('idn_access_assignments.user_id', $target->getKey())
            ->where('idn_access_assignments.facility_id', $facilityId)
            ->where('idn_access_assignments.status', 'ACTIVE')
            ->get(['idn_roles.key', 'idn_access_assignments.is_primary']);
        expect($assignments)->toHaveCount(1);
        expect((bool) $assignments->firstWhere('key', 'nurse')->is_primary)->toBeTrue();
        expect($assignments->firstWhere('key', 'receptionist'))->toBeNull();
        expect(DB::table('idn_admin_role_promotion_requests')
            ->where('tenant_id', $tenantId)
            ->where('target_user_id', $target->getKey())
            ->where('role_key', 'receptionist')
            ->value('status'))->toBe('REJECTED');
    });
});

it('invites and links a selected facility account from the matching HR staff record', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacEnsureNurseRole($tenantId);
    sacEnsureReceptionistRole($tenantId);
    $hrManager = sacMakeHrOnly($tenantId, $facilityId, 'hr.inviter@example.test');
    sacSelectFacility($this, $facilityId);
    Mail::fake();

    $staffId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $staffId): void {
        $personId = createPersonHelper($tenantId, 'Nuru', null, 'Nurse');
        app(CommandBus::class)->dispatch(new CreateStaff(personId: $personId, staffId: $staffId));
        DB::table('hr_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'facility_id' => $facilityId,
            'scope' => 'FACILITY',
            'effective_from' => now()->toDateString(),
            'is_primary' => true,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($hrManager)
        ->get(route('hr.staff.show', $staffId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canInviteUser', true)
            ->where('invitationRoles.0.key', 'nurse'));

    $this->actingAs($hrManager)
        ->post(route('hr.staff.invite-account', $staffId), [
            'email' => 'nuru.staff.account@example.test',
            'primary_role_key' => 'nurse',
            'secondary_role_keys' => ['receptionist'],
            'change_reference' => 'HR-STAFF-INV-1',
            'access_reason' => 'Provide nursing and reception cover.',
        ])
        ->assertRedirect(route('hr.staff.show', ['staff' => $staffId, 'section' => 'access']))
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'linked to this staff record'));

    $invitationId = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $staffId): string {
        $linkedAccount = DB::table('hr_staff_user_links as link')
            ->join('users', 'users.id', '=', 'link.user_id')
            ->where('link.tenant_id', $tenantId)
            ->where('link.staff_id', $staffId)
            ->first(['users.id', 'users.email']);
        expect($linkedAccount)->not->toBeNull()
            ->and($linkedAccount->email)->toBe('nuru.staff.account@example.test');

        $invitation = DB::table('aut_admin_bootstrap_tokens')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $linkedAccount->id)
            ->first(['id', 'role_key', 'role_keys']);
        expect($invitation->role_key)->toBe('nurse')
            ->and(json_decode($invitation->role_keys, true))->toBe(['nurse', 'receptionist']);

        return (string) $invitation->id;
    });

    $firstToken = TenantContextScope::run($tenantId, $facilityId, function () use ($invitationId): array {
        $row = DB::table('aut_admin_bootstrap_tokens')->where('id', $invitationId)
            ->first(['id', 'status', 'expires_at', 'created_by_reference', 'role_keys']);

        return [
            'status' => (string) $row->status,
            'expires_at' => (string) $row->expires_at,
            'reference' => (string) $row->created_by_reference,
            'roles' => json_decode($row->role_keys, true),
        ];
    });
    expect($firstToken['status'])->toBe('PENDING');

    $this->actingAs($hrManager)
        ->post(route('hr.staff.resend-invitation', $staffId), [
            'change_reference' => 'HR-STAFF-RESEND-1',
            'access_reason' => 'The staff member did not receive the earlier invitation.',
        ])
        ->assertRedirect(route('hr.staff.show', ['staff' => $staffId, 'section' => 'access']))
        ->assertSessionHas('status', 'A fresh setup invitation was sent. The link expires in 60 minutes.');

    [$firstStatus, $secondInvitationId] = TenantContextScope::run(
        $tenantId,
        $facilityId,
        function () use ($invitationId, $tenantId, $staffId): array {
            $linkedUserId = DB::table('hr_staff_user_links')
                ->where('tenant_id', $tenantId)
                ->where('staff_id', $staffId)
                ->value('user_id');
            $latest = DB::table('aut_admin_bootstrap_tokens')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $linkedUserId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first(['id', 'status', 'created_by_reference', 'role_keys', 'expires_at']);
            expect((string) $latest->created_by_reference)->toBe('HR-STAFF-RESEND-1')
                ->and(json_decode($latest->role_keys, true))->toBe(['nurse', 'receptionist'])
                ->and(now()->lt($latest->expires_at))->toBeTrue();

            return [
                (string) DB::table('aut_admin_bootstrap_tokens')->where('id', $invitationId)->value('status'),
                (string) $latest->id,
            ];
        },
    );
    expect($firstStatus)->toBe('SUPERSEDED');

    TenantContextScope::run($tenantId, $facilityId, function () use ($secondInvitationId): void {
        DB::table('aut_admin_bootstrap_tokens')->where('id', $secondInvitationId)->update([
            'expires_at' => now()->subMinute(),
        ]);
    });

    $this->actingAs($hrManager)
        ->get(route('hr.staff.show', $staffId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staff.setupInvitation.expired', true)
            ->where('staff.setupInvitation.canResend', true)
            ->where('staff.setupInvitation.canRevoke', true)
            ->where('canResendInvitation', true)
            ->where('canRevokeInvitation', true));

    $this->actingAs($hrManager)
        ->get(route('hr.staff.index', ['status' => 'PROSPECT']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staff.0.id', $staffId)
            ->where('activeStatus', 'PROSPECT'));

    $this->actingAs($hrManager)
        ->get(route('hr.staff.account-invitations'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/AccountInvitations')
            ->where('invitations.0.staffId', $staffId)
            ->where('invitations.0.email', 'nuru.staff.account@example.test')
            ->where('invitations.0.status', 'PENDING')
            ->where('invitations.0.expired', true));

    $this->actingAs($hrManager)
        ->post(route('hr.staff.resend-invitation', $staffId), [
            'change_reference' => 'HR-STAFF-RESEND-2',
            'access_reason' => 'The staff member still did not receive the invitation.',
        ])
        ->assertRedirect(route('hr.staff.show', ['staff' => $staffId, 'section' => 'access']))
        ->assertSessionHas('status', 'A fresh setup invitation was sent. The link expires in 60 minutes.');

    TenantContextScope::run($tenantId, $facilityId, function () use ($secondInvitationId, $staffId, $tenantId): void {
        $latest = DB::table('aut_admin_bootstrap_tokens as token')
            ->join('hr_staff_user_links as link', 'link.user_id', '=', 'token.user_id')
            ->where('token.tenant_id', $tenantId)
            ->where('link.staff_id', $staffId)
            ->orderByDesc('token.created_at')
            ->orderByDesc('token.id')
            ->first(['token.id', 'token.status', 'token.created_by_reference', 'token.role_keys', 'token.expires_at']);
        expect((string) $latest->created_by_reference)->toBe('HR-STAFF-RESEND-2')
            ->and((string) $latest->status)->toBe('PENDING')
            ->and(json_decode($latest->role_keys, true))->toBe(['nurse', 'receptionist'])
            ->and(now()->lt($latest->expires_at))->toBeTrue()
            ->and((string) DB::table('aut_admin_bootstrap_tokens')->where('id', $secondInvitationId)->value('status'))
            ->toBe('SUPERSEDED');
    });
    Mail::assertSent(AdminBootstrapInvitation::class, 3);
});

it('completes first access for an invited clinician and lets HR approve the pending role', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacSelectFacility($this, $facilityId);
    Mail::fake();

    $staffId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $staffId): void {
        $personId = createPersonHelper($tenantId, 'Hawa', null, 'Staff Member');
        app(CommandBus::class)->dispatch(new CreateStaff(personId: $personId, staffId: $staffId));
        DB::table('hr_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'facility_id' => $facilityId,
            'scope' => 'FACILITY',
            'effective_from' => now()->toDateString(),
            'is_primary' => true,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.invite-account', $staffId), [
            'email' => 'hawa.clinician.account@example.test',
            'primary_role_key' => 'doctor',
            'secondary_role_keys' => [],
            'change_reference' => 'HR-STAFF-INV-CLINICIAN',
            'access_reason' => 'Provide clinical care at this facility.',
        ])
        ->assertRedirect(route('hr.staff.show', ['staff' => $staffId, 'section' => 'access']));

    $token = sacSetupToken();
    $this->post(route('admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongClinicianPassword!2026',
        'password_confirmation' => 'LongClinicianPassword!2026',
    ])->assertRedirect(route('login'))
        ->assertSessionHas(
            'status',
            'Account setup is complete. The administrator who invited you must approve your access before you can sign in.',
        );

    $target = User::query()->where('email', 'hawa.clinician.account@example.test')->firstOrFail();
    $promotionId = TenantContextScope::run($tenantId, null, fn (): string => (string) DB::table('idn_admin_role_promotion_requests')
        ->where('tenant_id', $tenantId)
        ->where('target_user_id', $target->getKey())
        ->where('role_key', 'doctor')
        ->value('id'));

    $this->actingAs($fixture['user'])
        ->get(route('hr.staff.show', $staffId))
        ->assertInertia(fn ($page) => $page
            ->where('pendingInvitationAccessRequests.0.id', $promotionId)
            ->where('pendingInvitationAccessRequests.0.roleLabel', 'Clinician'));

    $otherHr = sacMakeHrOnly($tenantId, $facilityId, 'other.hr.inviter@example.test');
    $this->actingAs($otherHr)
        ->post(route('hr.staff.access-requests.approve', [
            'promotion' => $promotionId,
            'staff' => $staffId,
        ]))
        ->assertNotFound();

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.access-requests.approve', [
            'promotion' => $promotionId,
            'staff' => $staffId,
        ]))
        ->assertRedirect(route('hr.staff.show', $staffId));

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $fixture, $target, $promotionId): void {
        $assignment = DB::table('idn_access_assignments as assignment')
            ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.user_id', $target->getKey())
            ->where('assignment.facility_id', $facilityId)
            ->where('role.key', 'doctor')
            ->first(['assignment.status']);

        expect($assignment)->not->toBeNull()
            ->and($assignment->status)->toBe('ACTIVE')
            ->and(DB::table('idn_admin_role_promotion_requests')
                ->where('id', $promotionId)
                ->value('decided_by'))->toBe($fixture['user']->getKey())
            ->and(DB::table('shared_audit_log')
                ->where('tenant_id', $tenantId)
                ->where('action', 'identity.admin_role_promotion.approved')
                ->where('actor_id', $fixture['user']->getKey())
                ->where('target_id', $target->getKey())
                ->exists())->toBeTrue();
    });
});

it('refuses to activate access when the inviter loses authority before setup', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access', 'identity.assignments.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacEnsureNurseRole($tenantId);
    $fixture['user']->forceFill([
        'two_factor_secret' => encrypt('x'), 'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    Mail::fake();

    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.role-promotions.facility-user-invitations.store'), [
            'role_key' => 'nurse',
            'facility_id' => $facilityId,
            'name' => 'Lost Auth',
            'email' => 'lost.auth@example.test',
            'change_reference' => 'CHG-005',
            'reason' => 'Provide nursing care at this facility.',
        ])->assertSessionHasNoErrors();
    $token = sacSetupToken();

    // Inviter loses all facility access before the invitee accepts.
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $fixture): void {
        DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $fixture['user']->getKey())->update(['status' => 'REVOKED']);
    });

    $this->post(route('admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongNursePassword!2026',
        'password_confirmation' => 'LongNursePassword!2026',
    ])->assertSessionHasErrors([
        'token' => 'The inviter no longer has permission to complete this access request. Your password was not saved; contact the inviter or your facility administrator to review and resend the invitation.',
    ]);

    TenantContextScope::run($tenantId, null, function (): void {
        $target = User::query()->where('email', 'lost.auth@example.test')->firstOrFail();
        expect($target->email_verified_at)->toBeNull();
    });
});

it('lets the inviting Tenant Admin approve Facility Admin access from an invitation', function (): void {
    $fixture = makeTenantWithUser();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    // Tenant admin inviter.
    $tenantAdmin = sacMakeIdentityAdmin($tenantId, $facilityId, 'tenant.inviter@example.test');
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $tenantAdmin, $fixture): void {
        // Elevate to tenant_admin role key for invite authority.
        $roleId = (string) Str::uuid7();
        DB::table('idn_roles')->insert([
            'id' => $roleId, 'tenant_id' => $tenantId, 'key' => 'tenant_admin',
            'name' => 'Tenant Admin', 'status' => 'ACTIVE', 'scope_type' => 'ORG',
            'is_protected' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['admin.workspace.access', 'identity.assignments.manage'] as $key) {
            $permId = DB::table('idn_permissions')->where('key', $key)->value('id') ?? (string) Str::uuid7();
            if (! DB::table('idn_permissions')->where('id', $permId)->exists()) {
                DB::table('idn_permissions')->insert(['id' => $permId, 'key' => $key, 'layer' => 'ACTION', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'role_id' => $roleId,
                'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $tenantAdmin->getKey(),
            'role_id' => $roleId, 'organization_id' => $fixture['organization']->id,
            'status' => 'ACTIVE', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Facility admin role must exist for invite.
        if (! DB::table('idn_roles')->where('tenant_id', $tenantId)->where('key', 'facility_admin')->exists()) {
            $faId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $faId, 'tenant_id' => $tenantId, 'key' => 'facility_admin',
                'name' => 'Facility Administrator', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY',
                'is_protected' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });
    Mail::fake();

    $this->actingAs($tenantAdmin)
        ->post(route('admin.identity.role-promotions.facility-user-invitations.store'), [
            'role_key' => 'facility_admin',
            'facility_id' => $facilityId,
            'name' => 'New Fac Admin',
            'email' => 'new.facadmin@example.test',
            'change_reference' => 'CHG-006',
            'reason' => 'Administer this facility operations.',
        ])->assertSessionHasNoErrors();

    $token = sacSetupToken();
    $this->post(route('admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongAdminPassword!2026',
        'password_confirmation' => 'LongAdminPassword!2026',
    ])->assertRedirect(route('login'));

    $targetId = '';
    $promotionId = '';
    TenantContextScope::run($tenantId, null, function () use ($tenantId, &$targetId, &$promotionId): void {
        $target = User::query()->where('email', 'new.facadmin@example.test')->firstOrFail();
        $promotion = DB::table('idn_admin_role_promotion_requests')->where('tenant_id', $tenantId)
            ->where('target_user_id', $target->getKey())->where('role_key', 'facility_admin')->first();
        expect($promotion)->not->toBeNull()
            ->and($promotion->status)->toBe('PENDING')
            ->and($promotion->request_origin)->toBe('FACILITY_INVITATION');
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $target->getKey())->where('status', 'ACTIVE')->exists())->toBeFalse();

        $requestAudit = DB::table('shared_audit_log')
            ->where('action', 'identity.admin_role_promotion.requested')
            ->where('target_id', $target->getKey())
            ->first();
        expect($requestAudit)->not->toBeNull();
        expect(json_decode((string) $requestAudit->metadata, true, 512, JSON_THROW_ON_ERROR))
            ->toMatchArray([
                'request_origin' => 'FACILITY_INVITATION',
                'self_approval_allowed' => true,
            ]);

        $targetId = (string) $target->getKey();
        $promotionId = (string) $promotion->id;
    });

    // Losing the Tenant Admin role removes the invitation self-approval
    // exception even if facility-level assignment-management authority remains.
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $tenantAdmin): void {
        $tenantAdminRoleId = DB::table('idn_roles')->where('tenant_id', $tenantId)
            ->where('key', 'tenant_admin')->value('id');
        DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $tenantAdmin->getKey())
            ->where('role_id', $tenantAdminRoleId)
            ->update(['status' => 'REVOKED', 'updated_at' => now()]);
    });

    $this->actingAs($tenantAdmin)
        ->post(route('admin.identity.role-promotions.approve', $promotionId))
        ->assertForbidden();

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $promotionId, $targetId): void {
        expect(DB::table('idn_admin_role_promotion_requests')->where('id', $promotionId)->value('status'))
            ->toBe('PENDING');
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $targetId)->where('status', 'ACTIVE')->exists())->toBeFalse();
    });

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $tenantAdmin): void {
        $tenantAdminRoleId = DB::table('idn_roles')->where('tenant_id', $tenantId)
            ->where('key', 'tenant_admin')->value('id');
        DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $tenantAdmin->getKey())
            ->where('role_id', $tenantAdminRoleId)
            ->update(['status' => 'ACTIVE', 'updated_at' => now()]);
    });

    $this->actingAs($tenantAdmin)
        ->post(route('admin.identity.role-promotions.approve', $promotionId))
        ->assertRedirect(route('admin.identity.role-promotions'));

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $tenantAdmin, $targetId, $promotionId): void {
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $targetId)->where('status', 'ACTIVE')->exists())->toBeTrue();

        $promotion = DB::table('idn_admin_role_promotion_requests')->where('tenant_id', $tenantId)
            ->where('id', $promotionId)->first();
        expect($promotion)->not->toBeNull()
            ->and($promotion->status)->toBe('APPROVED')
            ->and($promotion->decided_by)->toBe($tenantAdmin->getKey());

        $audit = DB::table('shared_audit_log')->where('action', 'identity.admin_role_promotion.approved')
            ->where('target_id', $targetId)->first();
        expect($audit)->not->toBeNull();
        $metadata = json_decode((string) $audit->metadata, true, 512, JSON_THROW_ON_ERROR);
        expect($metadata)->toMatchArray([
            'self_approved' => true,
            'approval_mode' => 'INVITING_TENANT_ADMIN',
            'request_origin' => 'FACILITY_INVITATION',
        ]);
    });
});

it('blocks requester and target self-approval on admin promotions', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access', 'identity.assignments.manage', 'identity.assignments.read']);
    $tenantId = (string) $fixture['tenant']->id;
    $fixture['user']->forceFill([
        'two_factor_secret' => encrypt('x'), 'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    TenantContextScope::run($tenantId, null, function () use ($tenantId): void {
        foreach (['tenant_admin', 'facility_admin'] as $key) {
            if (DB::table('idn_roles')->where('tenant_id', $tenantId)->where('key', $key)->exists()) {
                continue;
            }
            DB::table('idn_roles')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'key' => $key,
                'name' => $key, 'status' => 'ACTIVE', 'scope_type' => $key === 'tenant_admin' ? 'ORG' : 'FACILITY',
                'is_protected' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });
    $target = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);
    // Give the target an ACTIVE assignment so login middleware passes;
    // the controller must still block target self-approval.
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $fixture, $target): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'user_id' => $target->getKey(), 'role_id' => $fixture['role_id'],
            'facility_id' => $fixture['facility']->id, 'status' => 'ACTIVE',
            'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.role-promotions'), [
            'role_key' => 'facility_admin',
            'target_user_id' => (string) $target->getKey(),
            'facility_id' => (string) $fixture['facility']->id,
            'reason' => 'Need another facility administrator.',
        ])->assertRedirect();

    $promotionId = TenantContextScope::run($tenantId, null, fn () => (string) DB::table('idn_admin_role_promotion_requests')
        ->where('tenant_id', $tenantId)->where('target_user_id', $target->getKey())->value('id'));

    // Requester cannot approve own request.
    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.role-promotions.approve', $promotionId))
        ->assertForbidden();
    // Target cannot approve own promotion.
    $target->forceFill([
        'two_factor_secret' => encrypt('x'), 'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $this->actingAs($target)
        ->post(route('admin.identity.role-promotions.approve', $promotionId))
        ->assertForbidden();
});

it('suspends linked facility access immediately while preserving HR history', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacSelectFacility($this, $facilityId);
    $nurseRoleId = sacEnsureNurseRole($tenantId);

    $personId = createPersonHelper($tenantId, 'Susi', 'K', 'Suspend', facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    $assignmentId = assignStaffHelper($tenantId, $staffId, $facilityId);
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        app(CommandBus::class)->dispatch(new OnboardStaff($staffId, '2026-01-05'));
    });
    $linked = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $linked, $nurseRoleId): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $nurseRoleId, 'facility_id' => $facilityId, 'status' => 'ACTIVE',
            'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('aut_sessions')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'session_id' => 'sess-suspend-1', 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
            'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    linkStaffUserHelper($tenantId, $staffId, (string) $linked->getKey(), $facilityId);

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.suspend', $staffId), ['reason' => 'Misconduct review pending.'])
        ->assertRedirect();

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $linked, $staffId): void {
        expect(DB::table('hr_staff')->where('id', $staffId)->value('status'))->toBe('SUSPENDED');
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('facility_id', $facilityId)->value('status'))->toBe('SUSPENDED');
        expect(DB::table('aut_sessions')->where('tenant_id', $tenantId)->where('user_id', $linked->getKey())
            ->whereNull('revoked_at')->exists())->toBeFalse();
        // ScopeChecker denies: no effective permissions from suspended assignment.
        $perms = app(ScopeChecker::class)->effectivePermissions($linked, $facilityId);
        expect($perms)->not->toContain('patient.patients.read');
        expect(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
            ->where('action', 'identity.access.suspended')->where('target_id', $linked->getKey())->exists())->toBeTrue();
    });
});

it('revokes linked facility access on termination and never restores revoked access', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacSelectFacility($this, $facilityId);
    $nurseRoleId = sacEnsureNurseRole($tenantId);

    $personId = createPersonHelper($tenantId, 'Tina', 'K', 'Terminate', facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    $hrAssignment = assignStaffHelper($tenantId, $staffId, $facilityId);
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        app(CommandBus::class)->dispatch(new OnboardStaff($staffId, '2026-01-05'));
    });
    $linked = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $linked, $nurseRoleId): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $nurseRoleId, 'facility_id' => $facilityId, 'status' => 'ACTIVE',
            'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    linkStaffUserHelper($tenantId, $staffId, (string) $linked->getKey(), $facilityId);
    // End HR placement so termination preconditions pass.
    TenantContextScope::run($tenantId, $facilityId, function () use ($hrAssignment): void {
        app(CommandBus::class)->dispatch(new EndAssignment($hrAssignment, CarbonImmutable::parse('2026-02-01'), 'Placement ended.'));
    });

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.terminate', $staffId), [
            'terminated_date' => '2026-03-01',
            'reason' => 'End of contract departure.',
        ])->assertRedirect();

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $linked, $staffId): void {
        expect(DB::table('hr_staff')->where('id', $staffId)->value('status'))->toBe('TERMINATED');
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('facility_id', $facilityId)->value('status'))->toBe('REVOKED');
        $perms = app(ScopeChecker::class)->effectivePermissions($linked, $facilityId);
        expect($perms)->not->toContain('patient.patients.read');
        expect(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
            ->where('action', 'identity.access.revoked')->where('target_id', $linked->getKey())->exists())->toBeTrue();
    });
});

it('restores suspended but never revoked access on reinstatement', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacSelectFacility($this, $facilityId);
    $nurseRoleId = sacEnsureNurseRole($tenantId);
    $receptionistRoleId = sacEnsureReceptionistRole($tenantId);

    $personId = createPersonHelper($tenantId, 'Rita', 'K', 'Reinstate', facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        app(CommandBus::class)->dispatch(new OnboardStaff($staffId, '2026-01-05'));
    });
    $linked = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);
    $revokedId = null;
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $linked, $nurseRoleId, $receptionistRoleId, &$revokedId): void {
        DB::table('idn_access_assignments')->insert([
            'id' => $susp = (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $nurseRoleId, 'facility_id' => $facilityId, 'status' => 'ACTIVE',
            'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $revokedId = (string) Str::uuid7();
        DB::table('idn_access_assignments')->insert([
            'id' => $revokedId, 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $receptionistRoleId, 'facility_id' => $facilityId, 'status' => 'REVOKED',
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    linkStaffUserHelper($tenantId, $staffId, (string) $linked->getKey(), $facilityId);

    $this->actingAs($fixture['user'])->post(route('hr.staff.suspend', $staffId), ['reason' => 'Temporary review suspension.'])->assertRedirect();
    $this->actingAs($fixture['user'])->post(route('hr.staff.reinstate', $staffId))->assertRedirect();

    // Re-check with explicit staff id via fresh query.
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $linked, $staffId, $revokedId, $nurseRoleId): void {
        expect(DB::table('hr_staff')->where('id', $staffId)->value('status'))->toBe('ACTIVE');
        $nurseStatus = DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('role_id', $nurseRoleId)->value('status');
        expect($nurseStatus)->toBe('ACTIVE');
        expect(DB::table('idn_access_assignments')->where('id', $revokedId)->value('status'))->toBe('REVOKED');
        expect(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
            ->where('action', 'identity.access.restored')->where('target_id', $linked->getKey())->exists())->toBeTrue();
    });
});

it('grants and revokes ordinary access for existing users with scope enforcement', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access', 'identity.assignments.manage', 'identity.assignments.read']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacEnsureNurseRole($tenantId);
    sacEnsureReceptionistRole($tenantId);
    $fixture['user']->forceFill([
        'two_factor_secret' => encrypt('x'), 'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $existing = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);

    // Grant nurse at managed facility.
    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.facility-assignments.store'), [
            'facility_id' => $facilityId,
            'email' => $existing->email,
            'role_key' => 'nurse',
            'reason' => 'Cover night shift nursing.',
        ])->assertRedirect(route('admin.identity.role-promotions'));

    $assignmentId = TenantContextScope::run($tenantId, null, fn () => (string) DB::table('idn_access_assignments')
        ->where('tenant_id', $tenantId)->where('user_id', $existing->getKey())
        ->where('facility_id', $facilityId)->value('id'));
    expect($assignmentId)->toBeString();

    // Duplicate grant rejected with inline validation error + toast (no 409 page, no page-top alert).
    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.facility-assignments.store'), [
            'facility_id' => $facilityId,
            'email' => $existing->email,
            'role_key' => 'nurse',
            'reason' => 'Duplicate cover request.',
        ])->assertSessionHasErrors('email')
        ->assertSessionHas('error', 'This user already has the selected role at this facility.');

    // Admin roles cannot use the ordinary endpoint.
    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.facility-assignments.store'), [
            'facility_id' => $facilityId,
            'email' => $existing->email,
            'role_key' => 'facility_admin',
            'reason' => 'Attempted escalation path.',
        ])->assertSessionHasErrors('role_key');

    // Revoke with reason: immediate REVOKED + audit.
    $this->actingAs($fixture['user'])
        ->delete(route('admin.identity.facility-assignments.destroy', $assignmentId), ['reason' => 'Shift cover ended.'])
        ->assertRedirect(route('admin.identity.role-promotions'));

    TenantContextScope::run($tenantId, null, function () use ($assignmentId, $existing, $facilityId): void {
        expect(DB::table('idn_access_assignments')->where('id', $assignmentId)->value('status'))->toBe('REVOKED');
        $perms = app(ScopeChecker::class)->effectivePermissions($existing, $facilityId);
        expect($perms)->toBe([]);
    });
});

it('shows linked staff roles and manages each ordinary assignment independently', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $hrManager = sacMakeHrOnly($tenantId, $facilityId, 'role.manager@example.test');
    $staffAccount = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $staffId = (string) Str::uuid7();
    $nurseRoleId = sacEnsureNurseRole($tenantId);
    $receptionistRoleId = sacEnsureReceptionistRole($tenantId);

    TenantContextScope::run($tenantId, $facilityId, function () use (
        $tenantId,
        $facilityId,
        $staffId,
        $staffAccount,
        $hrManager,
        $nurseRoleId,
        $receptionistRoleId,
    ): void {
        $personId = createPersonHelper($tenantId, 'Amina', null, 'Roleholder');
        app(CommandBus::class)->dispatch(new CreateStaff(personId: $personId, staffId: $staffId));
        DB::table('hr_staff')->where('tenant_id', $tenantId)->where('id', $staffId)
            ->update(['status' => 'ACTIVE']);
        DB::table('hr_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'facility_id' => $facilityId,
            'scope' => 'FACILITY',
            'effective_from' => now()->toDateString(),
            'is_primary' => true,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('hr_staff_user_links')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'user_id' => $staffAccount->getKey(),
            'linked_at' => now(),
            'linked_by' => $hrManager->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$nurseRoleId, $receptionistRoleId] as $roleId) {
            DB::table('idn_access_assignments')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'user_id' => $staffAccount->getKey(),
                'role_id' => $roleId,
                'status' => 'ACTIVE',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });
    sacSelectFacility($this, $facilityId);
    $assignmentIds = TenantContextScope::run($tenantId, null, fn (): array => DB::table('idn_access_assignments')
        ->where('tenant_id', $tenantId)
        ->where('user_id', $staffAccount->getKey())
        ->orderBy('role_id')
        ->pluck('id')
        ->map(static fn ($id): string => (string) $id)
        ->all());
    $nurseAssignmentId = TenantContextScope::run($tenantId, null, fn (): string => (string) DB::table('idn_access_assignments as assignment')
        ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
        ->where('assignment.tenant_id', $tenantId)
        ->where('assignment.user_id', $staffAccount->getKey())
        ->where('role.key', 'nurse')
        ->value('assignment.id'));

    $this->actingAs($hrManager)
        ->get(route('hr.staff.show', $staffId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accessAssignments', 2)
            ->where('canManageStaffAccess', true));

    $this->actingAs($hrManager)
        ->post(route('hr.staff.access-assignments.manage', [
            'staff' => $staffId,
            'assignment' => $nurseAssignmentId,
            'action' => 'suspend',
        ]), ['reason' => 'Temporary coverage change.'])
        ->assertRedirect(route('hr.staff.show', ['staff' => $staffId]));

    TenantContextScope::run($tenantId, null, function () use ($assignmentIds, $nurseAssignmentId, $tenantId): void {
        expect(DB::table('idn_access_assignments')->where('id', $nurseAssignmentId)->value('status'))->toBe('SUSPENDED');
        $otherAssignment = DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $assignmentIds)
            ->where('id', '!=', $nurseAssignmentId)
            ->first();
        expect($otherAssignment?->status)->toBe('ACTIVE');
        expect(DB::table('shared_audit_log')
            ->where('action', 'identity.access.suspended')
            ->where('target_id', $nurseAssignmentId)
            ->whereJsonContains('metadata->role', 'nurse')
            ->exists())->toBeTrue();
    });

    $this->actingAs($hrManager)
        ->post(route('hr.staff.access-assignments.manage', [
            'staff' => $staffId,
            'assignment' => $nurseAssignmentId,
            'action' => 'restore',
        ]), ['reason' => 'Coverage is restored.'])
        ->assertRedirect(route('hr.staff.show', ['staff' => $staffId]));

    $this->actingAs($hrManager)
        ->post(route('hr.staff.access-assignments.manage', [
            'staff' => $staffId,
            'assignment' => $nurseAssignmentId,
            'action' => 'revoke',
        ]), ['reason' => 'Nursing role no longer required.'])
        ->assertRedirect(route('hr.staff.show', ['staff' => $staffId]));

    $this->actingAs($hrManager)
        ->get(route('hr.staff.show', $staffId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accessAssignments.0.isLifecycleSuspended', false)
            ->has('accessHistory', 3));

    $this->actingAs($hrManager)
        ->post(route('hr.staff.access-assignments.manage', [
            'staff' => (string) Str::uuid7(),
            'assignment' => $assignmentIds[0],
            'action' => 'restore',
        ]), ['reason' => 'Cross-staff access must fail.'])
        ->assertNotFound();
});

it('provisions routine laboratory access only at facilities with the laboratory capability enabled', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access', 'identity.assignments.manage', 'identity.assignments.read']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $fixture['user']->forceFill([
        'two_factor_secret' => encrypt('x'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $existing = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);

    $payload = [
        'facility_id' => $facilityId,
        'email' => $existing->email,
        'role_key' => 'laboratory_technician',
        'reason' => 'Assign routine laboratory duties.',
    ];
    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.facility-assignments.store'), $payload)
        ->assertUnprocessable();

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId): void {
        DB::table('org_facility_capabilities')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'capability_code' => 'LABORATORY',
            'state' => 'ENABLED',
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($fixture['user'])
        ->get(route('admin.identity.role-promotions'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('facilities.0.capabilities', ['LABORATORY'])
            ->has('ordinaryRoleOptions', 6)
            ->where('ordinaryRoleOptions.0.key', 'billing_admin')
            ->where('ordinaryRoleOptions.2.key', 'doctor')
            ->where('ordinaryRoleOptions.2.label', 'Clinician')
            ->where('ordinaryRoleOptions.3.key', 'laboratory_technician')
            ->where('ordinaryRoleOptions.3.requiredCapability', 'LABORATORY'));

    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.facility-assignments.store'), $payload)
        ->assertRedirect(route('admin.identity.role-promotions'));

    $roleId = TenantContextScope::run($tenantId, null, fn (): string => (string) DB::table('idn_roles')
        ->where('tenant_id', $tenantId)
        ->where('key', 'laboratory_technician')
        ->where('is_protected', true)
        ->value('id'));
    expect($roleId)->not->toBe('');
    $permissions = TenantContextScope::run($tenantId, null, fn (): array => DB::table('idn_permission_role as pr')
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

it('provisions routine pharmacist access only at facilities with the pharmacy capability enabled', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access', 'identity.assignments.manage', 'identity.assignments.read']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $fixture['user']->forceFill([
        'two_factor_secret' => encrypt('x'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $existing = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);

    $payload = [
        'facility_id' => $facilityId,
        'email' => $existing->email,
        'role_key' => 'pharmacist',
        'reason' => 'Assign routine pharmacy duties.',
    ];
    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.facility-assignments.store'), $payload)
        ->assertUnprocessable();

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId): void {
        DB::table('org_facility_capabilities')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'capability_code' => 'PHARMACY',
            'state' => 'ENABLED',
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($fixture['user'])
        ->get(route('admin.identity.role-promotions'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('facilities.0.capabilities', ['PHARMACY'])
            ->has('ordinaryRoleOptions', 6)
            ->where('ordinaryRoleOptions.2.key', 'doctor')
            ->where('ordinaryRoleOptions.2.label', 'Clinician')
            ->where('ordinaryRoleOptions.4.key', 'pharmacist')
            ->where('ordinaryRoleOptions.4.requiredCapability', 'PHARMACY'));

    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.facility-assignments.store'), $payload)
        ->assertRedirect(route('admin.identity.role-promotions'));

    $roleId = TenantContextScope::run($tenantId, null, fn (): string => (string) DB::table('idn_roles')
        ->where('tenant_id', $tenantId)
        ->where('key', 'pharmacist')
        ->where('is_protected', true)
        ->value('id'));
    expect($roleId)->not->toBe('');
    $permissions = TenantContextScope::run($tenantId, null, fn (): array => DB::table('idn_permission_role as pr')
        ->join('idn_permissions as permission', 'permission.id', '=', 'pr.permission_id')
        ->where('pr.role_id', $roleId)
        ->orderBy('permission.key')
        ->pluck('permission.key')
        ->map(static fn ($key): string => (string) $key)
        ->all());
    expect($permissions)->toEqual(collect(PharmacistRoleDefinition::PERMISSIONS)->sort()->values()->all());
});

it('provisions every universal staff role on demand without pre-existing rows', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access', 'identity.assignments.manage', 'identity.assignments.read']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $fixture['user']->forceFill([
        'two_factor_secret' => encrypt('x'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    // No role rows exist yet and no capability is enabled: exactly the five
    // universal roles are offered, keyed and sorted by the catalog.
    $this->actingAs($fixture['user'])
        ->get(route('admin.identity.role-promotions'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('ordinaryRoleOptions', 5)
            ->where('ordinaryRoleOptions.0.key', 'billing_admin')
            ->where('ordinaryRoleOptions.1.key', 'cashier')
            ->where('ordinaryRoleOptions.2.key', 'doctor')
            ->where('ordinaryRoleOptions.3.key', 'nurse')
            ->where('ordinaryRoleOptions.4.key', 'receptionist'));

    $expectedPermissions = [
        'nurse' => NurseRoleDefinition::PERMISSIONS,
        'receptionist' => ReceptionistRoleDefinition::PERMISSIONS,
        'cashier' => CashierRoleDefinition::PERMISSIONS,
        'billing_admin' => BillingAdminRoleDefinition::PERMISSIONS,
    ];
    foreach ($expectedPermissions as $roleKey => $permissions) {
        $target = User::factory()->forTenant($tenantId)->create([
            'email_verified_at' => now(),
            'status' => 'ACTIVE',
        ]);
        $this->actingAs($fixture['user'])
            ->post(route('admin.identity.facility-assignments.store'), [
                'facility_id' => $facilityId,
                'email' => $target->email,
                'role_key' => $roleKey,
                'reason' => "Assign routine {$roleKey} duties at this facility.",
            ])
            ->assertRedirect(route('admin.identity.role-promotions'));

        $roleId = TenantContextScope::run($tenantId, null, fn (): string => (string) DB::table('idn_roles')
            ->where('tenant_id', $tenantId)
            ->where('key', $roleKey)
            ->where('is_protected', true)
            ->where('scope_type', 'FACILITY')
            ->where('status', 'ACTIVE')
            ->value('id'));
        expect($roleId)->not->toBe('');
        $actual = TenantContextScope::run($tenantId, null, fn (): array => DB::table('idn_permission_role as pr')
            ->join('idn_permissions as permission', 'permission.id', '=', 'pr.permission_id')
            ->where('pr.role_id', $roleId)
            ->orderBy('permission.key')
            ->pluck('permission.key')
            ->map(static fn ($key): string => (string) $key)
            ->all());
        sort($expectedPermissions[$roleKey]);
        expect($actual)->toEqual($expectedPermissions[$roleKey]);
        expect(TenantContextScope::run($tenantId, null, fn (): bool => DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $target->getKey())
            ->where('role_id', $roleId)
            ->where('facility_id', $facilityId)
            ->where('status', 'ACTIVE')
            ->exists()))->toBeTrue();
    }
});

it('allows clinician and pharmacist grants at a dispensary without changing existing assignments', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access', 'identity.assignments.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $fixture['user']->forceFill([
        'two_factor_secret' => encrypt('x'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $existing = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $newAccount = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $legacyDoctorAssignmentId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, null, function () use (
        $tenantId,
        $facilityId,
        $fixture,
        $existing,
        $legacyDoctorAssignmentId,
    ): void {
        DB::table('org_facilities')->where('id', $facilityId)->update(['facility_type' => 'DISPENSARY']);
        $doctorRoleId = (string) Str::uuid7();
        $pharmacistRoleId = (string) Str::uuid7();
        foreach ([
            [$doctorRoleId, 'doctor', 'Clinician'],
            [$pharmacistRoleId, 'pharmacist', 'Pharmacist'],
        ] as [$roleId, $key, $name]) {
            DB::table('idn_roles')->insert([
                'id' => $roleId,
                'tenant_id' => $tenantId,
                'key' => $key,
                'name' => $name,
                'status' => 'ACTIVE',
                'scope_type' => 'FACILITY',
                'is_protected' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('idn_access_assignments')->insert([
            'id' => $legacyDoctorAssignmentId,
            'tenant_id' => $tenantId,
            'user_id' => $existing->getKey(),
            'role_id' => $doctorRoleId,
            'organization_id' => $fixture['organization']->id,
            'facility_id' => $facilityId,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('org_facility_capabilities')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'capability_code' => 'PHARMACY',
            'state' => 'ENABLED',
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    foreach (['doctor', 'pharmacist'] as $roleKey) {
        $this->actingAs($fixture['user'])
            ->post(route('admin.identity.facility-assignments.store'), [
                'facility_id' => $facilityId,
                'email' => $newAccount->email,
                'role_key' => $roleKey,
                'reason' => 'Assign new facility clinical access.',
            ])
            ->assertRedirect(route('admin.identity.role-promotions'));
    }

    expect(TenantContextScope::run(
        $tenantId,
        null,
        fn (): string => (string) DB::table('idn_access_assignments')
            ->where('id', $legacyDoctorAssignmentId)
            ->value('status'),
    ))->toBe('ACTIVE')
        ->and(TenantContextScope::run(
            $tenantId,
            null,
            fn (): int => DB::table('idn_access_assignments')
                ->where('user_id', $newAccount->getKey())
                ->where('facility_id', $facilityId)
                ->where('status', 'ACTIVE')
                ->count(),
        ))->toBe(2);
});

it('offers and provisions the shared Clinician role for a tenant that has no doctor role row yet', function (): void {
    $fixture = makeTenantWithUser([
        'admin.workspace.access',
        'identity.assignments.manage',
        'identity.assignments.read',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $fixture['user']->forceFill([
        'two_factor_secret' => encrypt('x'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $target = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('admin.identity.role-promotions'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('ordinaryRoleOptions', 5)
            ->where('ordinaryRoleOptions.2.key', 'doctor')
            ->where('ordinaryRoleOptions.2.label', 'Clinician'));

    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.facility-assignments.store'), [
            'facility_id' => $facilityId,
            'email' => $target->email,
            'role_key' => 'doctor',
            'reason' => 'Assign shared clinician access.',
        ])
        ->assertRedirect(route('admin.identity.role-promotions'));

    $roleId = TenantContextScope::run($tenantId, null, fn (): string => (string) DB::table('idn_roles')
        ->where('tenant_id', $tenantId)
        ->where('key', 'doctor')
        ->value('id'));
    expect($roleId)->not->toBe('');
    $permissions = TenantContextScope::run($tenantId, null, fn (): array => DB::table('idn_permission_role as pr')
        ->join('idn_permissions as permission', 'permission.id', '=', 'pr.permission_id')
        ->where('pr.role_id', $roleId)
        ->orderBy('permission.key')
        ->pluck('permission.key')
        ->map(static fn ($key): string => (string) $key)
        ->all());
    expect($permissions)->toEqual(collect(ClinicianRoleDefinition::PERMISSIONS)->sort()->values()->all());
});

it('rejects cross-facility and cross-tenant escalation on ordinary grants', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access', 'identity.assignments.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacEnsureNurseRole($tenantId);
    $otherFacility = TenantContextScope::run($tenantId, null, function () use ($tenantId, $fixture): string {
        $id = (string) Str::uuid7();
        DB::table('org_facilities')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'organization_id' => $fixture['organization']->id,
            'name' => 'Far Clinic', 'code' => 'FAR-01', 'facility_type' => 'DISPENSARY',
            'care_level' => 'PRIMARY', 'ownership_type' => 'PUBLIC', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    });
    $otherTenant = makeTenantWithUser(['admin.workspace.access', 'identity.assignments.manage']);
    $otherUser = User::factory()->forTenant((string) $otherTenant['tenant']->id)->create([
        'email_verified_at' => now(), 'status' => 'ACTIVE',
    ]);
    $existing = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);
    $fixture['user']->forceFill([
        'two_factor_secret' => encrypt('x'), 'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    // Unmanaged facility rejected (actor scoped to first facility only).
    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.facility-assignments.store'), [
            'facility_id' => $otherFacility,
            'email' => $existing->email,
            'role_key' => 'nurse',
            'reason' => 'Attempt outside managed scope.',
        ])->assertForbidden();

    // Cross-tenant target rejected (email belongs to another tenant).
    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.facility-assignments.store'), [
            'facility_id' => $facilityId,
            'email' => $otherUser->email,
            'role_key' => 'nurse',
            'reason' => 'Attempt cross-tenant grant.',
        ])->assertStatus(422);
});

it('leaves legacy pending ordinary requests resolvable through approval', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access', 'identity.assignments.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $nurseRoleId = sacEnsureNurseRole($tenantId);
    $requester = sacMakeIdentityAdmin($tenantId, $facilityId, 'legacy.requester@example.test');
    $approver = sacMakeIdentityAdmin($tenantId, $facilityId, 'legacy.approver@example.test');
    $target = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);
    $promotionId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $nurseRoleId, $requester, $target, $promotionId, $fixture): void {
        DB::table('idn_admin_role_promotion_requests')->insert([
            'id' => $promotionId, 'tenant_id' => $tenantId, 'organization_id' => $fixture['organization']->id,
            'facility_id' => $facilityId, 'target_user_id' => $target->getKey(), 'role_id' => $nurseRoleId,
            'role_key' => 'nurse', 'scope_type' => 'FACILITY', 'action' => 'PROMOTE', 'status' => 'PENDING',
            'reason' => 'Legacy HR-initiated nurse request.', 'requested_by' => $requester->getKey(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $this->actingAs($approver)
        ->post(route('admin.identity.role-promotions.approve', $promotionId))
        ->assertRedirect(route('admin.identity.role-promotions'));

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $target, $facilityId): void {
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $target->getKey())->where('facility_id', $facilityId)
            ->where('status', 'ACTIVE')->exists())->toBeTrue();
        expect(DB::table('idn_admin_role_promotion_requests')->where('id', DB::table('idn_admin_role_promotion_requests')->where('target_user_id', $target->getKey())->value('id'))->value('status'))->not->toBeNull();
    });
});

it('does not expose account invitations for suspended or future-assigned staff', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacEnsureNurseRole($tenantId);
    $identityAdmin = sacMakeIdentityAdmin($tenantId, $facilityId, 'suspended.staff.inviter@example.test');
    sacSelectFacility($this, $facilityId);
    Mail::fake();

    $staffId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $staffId): void {
        $personId = createPersonHelper($tenantId, 'Asha', null, 'Suspended');
        app(CommandBus::class)->dispatch(new CreateStaff(personId: $personId, staffId: $staffId));
        DB::table('hr_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'facility_id' => $facilityId,
            'scope' => 'FACILITY',
            'effective_from' => now()->toDateString(),
            'is_primary' => true,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('hr_staff')->where('tenant_id', $tenantId)->where('id', $staffId)
            ->update(['status' => 'SUSPENDED']);
    });

    $this->actingAs($identityAdmin)
        ->get(route('hr.staff.show', $staffId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canInviteUser', false)
            ->where('canLinkStaff', false)
            ->where('staff.hasCurrentFacilityAssignment', true)
            ->where('invitationRoles', []));

    $this->actingAs($identityAdmin)
        ->post(route('hr.staff.invite-account', $staffId), [
            'email' => 'suspended.staff.account@example.test',
            'primary_role_key' => 'nurse',
            'change_reference' => 'HR-STAFF-SUSP',
            'access_reason' => 'This request must not create access.',
        ])
        ->assertNotFound();

    expect(TenantContextScope::run($tenantId, null, fn (): bool => DB::table('users')
        ->where('tenant_id', $tenantId)
        ->where('email', 'suspended.staff.account@example.test')
        ->exists()))->toBeFalse();

    $existingAccount = User::factory()->forTenant($tenantId)->create([
        'email' => 'existing.suspended.staff@example.test',
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.link', $staffId), ['email' => $existingAccount->email])
        ->assertNotFound();
    expect(TenantContextScope::run($tenantId, null, fn (): bool => DB::table('hr_staff_user_links')
        ->where('tenant_id', $tenantId)
        ->where('staff_id', $staffId)
        ->exists()))->toBeFalse();

    $this->actingAs($fixture['user']);
    $futureStaffId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $futureStaffId): void {
        $personId = createPersonHelper($tenantId, 'Neema', null, 'Future');
        app(CommandBus::class)->dispatch(new CreateStaff(personId: $personId, staffId: $futureStaffId));
        DB::table('hr_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'staff_id' => $futureStaffId,
            'facility_id' => $facilityId,
            'scope' => 'FACILITY',
            'effective_from' => now()->addDay()->toDateString(),
            'is_primary' => true,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($identityAdmin)
        ->get(route('hr.staff.show', $futureStaffId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canInviteUser', false)
            ->where('staff.hasCurrentFacilityAssignment', false));

    $this->actingAs($identityAdmin)
        ->post(route('hr.staff.invite-account', $futureStaffId), [
            'email' => 'future.staff.account@example.test',
            'primary_role_key' => 'nurse',
            'change_reference' => 'HR-STAFF-FUT',
            'access_reason' => 'This request must wait for placement.',
        ])
        ->assertNotFound();
});

it('blocks active role grants to linked suspended or terminated staff', function (): void {
    $fixture = hrFixture([
        'hr.access',
        'hr.person.manage',
        'hr.staff.read',
        'hr.staff.manage',
        'hr.employment.manage',
        'hr.assignment.manage',
        'hr.staff_user_link.manage',
        'admin.workspace.access',
        'identity.assignments.manage',
        'identity.assignments.read',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $nurseRoleId = sacEnsureNurseRole($tenantId);
    $fixture['user']->forceFill([
        'two_factor_secret' => encrypt('x'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $targets = [];
    foreach (['ACTIVE', 'SUSPENDED', 'TERMINATED'] as $index => $status) {
        $personId = createPersonHelper(
            $tenantId,
            'Grant',
            'K',
            'Status'.$index,
            facilityId: $facilityId,
        );
        $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
        TenantContextScope::run($tenantId, null, function () use ($tenantId, $staffId, $status): void {
            DB::table('hr_staff')->where('tenant_id', $tenantId)->where('id', $staffId)
                ->update(['status' => $status]);
        });
        $target = User::factory()->forTenant($tenantId)->create([
            'email' => "grant.status{$index}@example.test",
            'email_verified_at' => now(),
            'status' => 'ACTIVE',
        ]);
        linkStaffUserHelper($tenantId, $staffId, (string) $target->getKey(), $facilityId);
        $targets[$status] = $target;
    }

    $this->actingAs($fixture['user'])
        ->post(route('admin.identity.facility-assignments.store'), [
            'facility_id' => $facilityId,
            'email' => $targets['ACTIVE']->email,
            'role_key' => 'nurse',
            'reason' => 'Grant access to active linked staff.',
        ])->assertRedirect(route('admin.identity.role-promotions'));

    foreach (['SUSPENDED', 'TERMINATED'] as $status) {
        $this->actingAs($fixture['user'])
            ->post(route('admin.identity.facility-assignments.store'), [
                'facility_id' => $facilityId,
                'email' => $targets[$status]->email,
                'role_key' => 'nurse',
                'reason' => 'Grant attempt must be denied.',
            ])->assertSessionHasErrors('email');
    }

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $targets, $nurseRoleId): void {
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)->where('role_id', $nurseRoleId)
            ->where('user_id', $targets['ACTIVE']->getKey())->where('status', 'ACTIVE')->exists())->toBeTrue();
        foreach (['SUSPENDED', 'TERMINATED'] as $status) {
            expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)->where('role_id', $nurseRoleId)
                ->where('user_id', $targets[$status]->getKey())->exists())->toBeFalse();
        }
    });
});

it('expires lapsed setup invitations with a timeline event and deduplicated HR alerts', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacEnsureNurseRole($tenantId);
    $hrManager = sacMakeHrOnly($tenantId, $facilityId, 'hr.expiry@example.test');
    sacSelectFacility($this, $facilityId);
    Mail::fake();

    $staffId = sacCreateInvitableStaff($tenantId, $facilityId);
    $this->actingAs($hrManager)
        ->post(route('hr.staff.invite-account', $staffId), [
            'email' => 'expiry.invitee@example.test',
            'primary_role_key' => 'nurse',
            'secondary_role_keys' => [],
            'change_reference' => 'HR-EXPIRY-1',
            'access_reason' => 'Provide nursing cover at this facility.',
        ])
        ->assertRedirect(route('hr.staff.show', ['staff' => $staffId, 'section' => 'access']));

    $invitationId = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $staffId): string {
        $id = DB::table('aut_admin_bootstrap_tokens as token')
            ->join('hr_staff_user_links as link', 'link.user_id', '=', 'token.user_id')
            ->where('token.tenant_id', $tenantId)
            ->where('link.staff_id', $staffId)
            ->orderByDesc('token.created_at')
            ->orderByDesc('token.id')
            ->value('token.id');
        expect($id)->toBeString();

        return (string) $id;
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $invitationId): void {
        DB::table('aut_admin_bootstrap_tokens')
            ->where('tenant_id', $tenantId)
            ->where('id', $invitationId)
            ->update(['expires_at' => now()->subMinute()]);
    });

    $this->artisan('identity:expire-facility-invitations', ['--once' => true])->assertSuccessful();

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $invitationId): void {
        $token = DB::table('aut_admin_bootstrap_tokens')
            ->where('tenant_id', $tenantId)
            ->where('id', $invitationId)
            ->first(['status', 'expiry_notified_at']);
        expect((string) $token->status)->toBe('EXPIRED')
            ->and($token->expiry_notified_at)->not->toBeNull();
        expect(DB::table('aut_facility_invitation_events')
            ->where('tenant_id', $tenantId)
            ->where('invitation_id', $invitationId)
            ->pluck('event_type')
            ->all())->toContain('ISSUED', 'DELIVERY_SENT', 'EXPIRED');
        $event = DB::table('aut_facility_invitation_events')
            ->where('tenant_id', $tenantId)
            ->where('invitation_id', $invitationId)
            ->where('event_type', 'EXPIRED');
        expect($event->update(['reason' => 'tampered']))->toBe(0);
        expect($event->delete())->toBe(0);
        expect(DB::table('aut_facility_invitation_events')
            ->where('tenant_id', $tenantId)
            ->where('invitation_id', $invitationId)
            ->where('event_type', 'EXPIRED')
            ->count())->toBe(1);
    });

    $queuedAlerts = Mail::queued(FacilityInvitationExpired::class);
    expect($queuedAlerts->count())->toBeGreaterThanOrEqual(1);
    Mail::assertQueued(
        FacilityInvitationExpired::class,
        fn (FacilityInvitationExpired $mail): bool => $mail->hasTo((string) $hrManager->email),
    );

    // A second sweep is idempotent: the expiry event and alert are not repeated.
    $this->artisan('identity:expire-facility-invitations', ['--once' => true])->assertSuccessful();
    Mail::assertQueued(FacilityInvitationExpired::class, $queuedAlerts->count());

    $this->actingAs($hrManager)
        ->get(route('hr.staff.show', $staffId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staff.setupInvitation.status', 'EXPIRED')
            ->where('staff.setupInvitation.expired', true)
            ->where('staff.setupInvitation.canResend', true)
            ->where('staff.setupInvitation.canRevoke', true));

    $this->actingAs($hrManager)
        ->get(route('hr.staff.onboarding', ['focus' => 'invitations']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Onboarding')
            ->where('rows.0.id', $staffId)
            ->where('rows.0.accountState', 'INVITATION_EXPIRED')
            ->where('rows.0.nextAction.kind', 'RESEND_INVITATION')
            ->where('rows.0.nextAction.label', 'Resend invitation')
            ->where('summary.expiredInvitations', 1));

    $this->actingAs($hrManager)
        ->get(route('hr.staff.index', ['status' => 'PROSPECT']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staff.0.accountState', 'INVITATION_EXPIRED')
            ->where('statusCounts.PROSPECT', 1));
});

it('revokes an unaccepted invitation, invalidates the old token and blocks resend', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacEnsureNurseRole($tenantId);
    $hrManager = sacMakeHrOnly($tenantId, $facilityId, 'hr.revoke@example.test');
    sacSelectFacility($this, $facilityId);
    Mail::fake();

    $staffId = sacCreateInvitableStaff($tenantId, $facilityId, 'Rashid', 'Revoke');
    $this->actingAs($hrManager)
        ->post(route('hr.staff.invite-account', $staffId), [
            'email' => 'revoke.invitee@example.test',
            'primary_role_key' => 'nurse',
            'secondary_role_keys' => [],
            'change_reference' => 'HR-REVOKE-1',
            'access_reason' => 'Provide nursing cover at this facility.',
        ])
        ->assertRedirect(route('hr.staff.show', ['staff' => $staffId, 'section' => 'access']));

    $setupToken = sacSetupToken();
    $invitationId = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $staffId): string {
        return (string) DB::table('aut_admin_bootstrap_tokens as token')
            ->join('hr_staff_user_links as link', 'link.user_id', '=', 'token.user_id')
            ->where('token.tenant_id', $tenantId)
            ->where('link.staff_id', $staffId)
            ->orderByDesc('token.created_at')
            ->orderByDesc('token.id')
            ->value('token.id');
    });

    $this->actingAs($hrManager)
        ->post(route('hr.staff.revoke-invitation', $staffId), [
            'reason' => 'The invitation was sent to the wrong email address.',
        ])
        ->assertRedirect(route('hr.staff.show', ['staff' => $staffId, 'section' => 'access']))
        ->assertSessionHas('status', 'The setup invitation was revoked. The account was not activated and no access was granted.');

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $invitationId, $hrManager): void {
        $token = DB::table('aut_admin_bootstrap_tokens')
            ->where('tenant_id', $tenantId)
            ->where('id', $invitationId)
            ->first(['status', 'revoked_at', 'revoked_by', 'revocation_reason']);
        expect((string) $token->status)->toBe('REVOKED')
            ->and($token->revoked_at)->not->toBeNull()
            ->and((string) $token->revoked_by)->toBe((string) $hrManager->getKey())
            ->and((string) $token->revocation_reason)->toBe('The invitation was sent to the wrong email address.');
        expect(DB::table('aut_facility_invitation_events')
            ->where('tenant_id', $tenantId)
            ->where('invitation_id', $invitationId)
            ->where('event_type', 'REVOKED')
            ->where('reason', 'The invitation was sent to the wrong email address.')
            ->exists())->toBeTrue();
    });

    // The revoked token must no longer complete account setup.
    $this->post(route('admin.bootstrap.complete'), [
        'token' => $setupToken,
        'password' => 'LongRevokedPassword!2026',
        'password_confirmation' => 'LongRevokedPassword!2026',
    ])->assertSessionHasErrors('token');

    $this->actingAs($hrManager)
        ->post(route('hr.staff.resend-invitation', $staffId), [
            'change_reference' => 'HR-REVOKE-2',
            'access_reason' => 'Attempt to resend after revocation must be denied.',
        ])
        ->assertSessionHasErrors('invitation');

    $this->actingAs($hrManager)
        ->get(route('hr.staff.show', $staffId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staff.setupInvitation.status', 'REVOKED')
            ->where('staff.setupInvitation.canResend', false)
            ->where('staff.setupInvitation.canRevoke', false)
            ->where('canUnlinkAccount', true));

    $this->actingAs($hrManager)
        ->get(route('hr.staff.onboarding', ['focus' => 'invitations']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Onboarding')
            ->where('rows.0.id', $staffId)
            ->where('rows.0.accountState', 'INVITATION_REVOKED')
            ->where('rows.0.nextAction.kind', 'REVIEW_INVITATION')
            ->where('rows.0.nextAction.label', 'Review invitation')
            ->where('summary.expiredInvitations', 0));

    $this->actingAs($hrManager)
        ->get(route('hr.staff.index', ['status' => 'PROSPECT']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staff.0.accountState', 'INVITATION_REVOKED'));
});

it('unlinks an unactivated account and keeps the revocation in the invitation timeline', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    sacEnsureNurseRole($tenantId);
    $hrManager = sacMakeHrOnly($tenantId, $facilityId, 'hr.unlink@example.test');
    sacSelectFacility($this, $facilityId);
    Mail::fake();

    $staffId = sacCreateInvitableStaff($tenantId, $facilityId, 'Amina', 'Unlink');
    $this->actingAs($hrManager)
        ->post(route('hr.staff.invite-account', $staffId), [
            'email' => 'unlink.invitee@example.test',
            'primary_role_key' => 'nurse',
            'secondary_role_keys' => [],
            'change_reference' => 'HR-UNLINK-1',
            'access_reason' => 'Provide nursing cover at this facility.',
        ])
        ->assertRedirect(route('hr.staff.show', ['staff' => $staffId, 'section' => 'access']));

    $invitationId = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $staffId): string {
        return (string) DB::table('aut_admin_bootstrap_tokens as token')
            ->join('hr_staff_user_links as link', 'link.user_id', '=', 'token.user_id')
            ->where('token.tenant_id', $tenantId)
            ->where('link.staff_id', $staffId)
            ->orderByDesc('token.created_at')
            ->orderByDesc('token.id')
            ->value('token.id');
    });

    $this->actingAs($hrManager)
        ->post(route('hr.staff.unlink-account', $staffId), [
            'reason' => 'The account was created for the wrong person and never activated.',
        ])
        ->assertRedirect(route('hr.staff.show', ['staff' => $staffId, 'section' => 'access']))
        ->assertSessionHas('status', 'The unactivated account was unlinked. You can now invite the correct email or link an existing account.');

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $staffId, $invitationId): void {
        expect(DB::table('hr_staff_user_links')
            ->where('tenant_id', $tenantId)
            ->where('staff_id', $staffId)
            ->exists())->toBeFalse();
        expect((string) DB::table('aut_admin_bootstrap_tokens')
            ->where('tenant_id', $tenantId)
            ->where('id', $invitationId)
            ->value('status'))->toBe('REVOKED');
        expect(DB::table('aut_facility_invitation_events')
            ->where('tenant_id', $tenantId)
            ->where('invitation_id', $invitationId)
            ->where('event_type', 'REVOKED')
            ->exists())->toBeTrue();
    });

    $this->actingAs($hrManager)
        ->get(route('hr.staff.show', $staffId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staff.linkedUserId', null)
            ->where('staff.setupInvitation', null)
            ->where('canUnlinkAccount', false));

    $this->actingAs($hrManager)
        ->get(route('hr.staff.index', ['status' => 'PROSPECT']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staff.0.accountState', 'NO_ACCOUNT'));
});
