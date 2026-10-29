<?php

declare(strict_types=1);

use App\Domains\HR\Application\Command\CreateStaff;
use App\Domains\HR\Application\Command\EndAssignment;
use App\Domains\HR\Application\Command\EndEmployment;
use App\Domains\Identity\Application\FacilityStaffManagerRoleProvisioner;
use App\Domains\Organization\Domain\Model\Facility;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/hr.php';

it('asks tenant-scoped staff managers to select a facility before opening HR', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $secondFacility = TenantContextScope::run($tenantId, null, function () use ($fixture, $tenantId): Facility {
        $facility = Facility::factory()->forTenant($tenantId, $fixture['organization']->id)->create([
            'name' => 'West Z Clinic',
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $fixture['user']->getKey(),
            'role_id' => $fixture['role_id'],
            'facility_id' => $facility->id,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $facility;
    });

    $this->actingAs($fixture['user'])
        ->get(route('hr.staff.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/SelectFacility')
            ->has('facilities', 2)
            ->where('facilities', fn ($facilities) => $facilities->contains(
                fn ($facility) => $facility['name'] === 'West Z Clinic',
            )));

    $this->actingAs($fixture['user'])
        ->from(route('hr.staff.index'))
        ->post(route('facility-context.update'), ['facility_id' => $secondFacility->id])
        ->assertRedirect(route('hr.staff.index'))
        ->assertSessionHas('active_facility_id', $secondFacility->id);

    $this->actingAs($fixture['user'])
        ->get(route('hr.staff.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Index')
            ->where('facility.id', (string) $secondFacility->id));
});

it('searches facility employees and user accounts and opens account details only within the active facility', function (): void {
    $fixture = hrFixture([
        'hr.access',
        'hr.person.manage',
        'hr.staff.read',
        'hr.staff.manage',
        'hr.assignment.manage',
        'identity.users.read',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $personId = createPersonHelper(
        tenantId: $tenantId,
        firstName: 'Amina',
        lastName: 'Search',
        email: 'amina.search@example.test',
        facilityId: $facilityId,
    );
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);
    $account = User::factory()->forTenant($tenantId)->create([
        'name' => 'Zawadi Account',
        'email' => 'zawadi.account@example.test',
        'status' => 'ACTIVE',
        'email_verified_at' => now(),
    ]);
    TenantContextScope::run($tenantId, $facilityId, function () use ($fixture, $tenantId, $facilityId, $account): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $account->getKey(),
            'role_id' => $fixture['role_id'],
            'facility_id' => $facilityId,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($fixture['user'])
        ->getJson(route('hr.people.search', ['q' => 'Search']))
        ->assertOk()
        ->assertJsonPath('results.0.type', 'employee')
        ->assertJsonPath('results.0.id', $staffId)
        ->assertJsonPath('results.0.name', 'Amina K Search')
        ->assertJsonPath('results.0.photoUrl', null);

    $this->actingAs($fixture['user'])
        ->getJson(route('hr.people.search', ['q' => 'Zawadi']))
        ->assertOk()
        ->assertJsonPath('results.0.type', 'user')
        ->assertJsonPath('results.0.id', (string) $account->getKey())
        ->assertJsonPath('results.0.photoUrl', null)
        ->assertJsonPath('results.0.href', route('hr.users.show', ['user' => $account->getKey()]));

    $this->actingAs($fixture['user'])
        ->get(route('hr.users.show', ['user' => $account->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Users/Show')
            ->where('account.name', 'Zawadi Account')
            ->where('facility.id', $facilityId)
            ->has('assignments', 1));

    TenantContextScope::run($tenantId, $facilityId, function () use ($fixture): void {
        DB::table('idn_permission_role')
            ->where('role_id', $fixture['role_id'])
            ->whereIn('permission_id', DB::table('idn_permissions')
                ->select('id')
                ->where('key', 'identity.users.read'))
            ->delete();
    });

    $this->actingAs($fixture['user'])
        ->getJson(route('hr.people.search', ['q' => 'Zawadi']))
        ->assertOk()
        ->assertJsonPath('results', []);

    $this->actingAs($fixture['user'])
        ->getJson(route('hr.people.search', ['q' => 'Search']))
        ->assertOk()
        ->assertJsonPath('results.0.name', 'Amina K Search')
        ->assertJsonPath('results.0.accountEmail', null);

    $this->actingAs($fixture['user'])
        ->get(route('hr.users.show', ['user' => $account->getKey()]))
        ->assertForbidden();
});

it('surfaces facility employees to assignment reviewers such as facility admins', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $personId = createPersonHelper(
        tenantId: $tenantId,
        firstName: 'Baraka',
        lastName: 'ReviewSearch',
        email: 'baraka.reviewsearch@example.test',
        facilityId: $facilityId,
    );
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);

    // Reviewer (facility-admin-like): assignment-review rights but no HR permissions.
    $reviewer = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $reviewerRoleId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $reviewer, $reviewerRoleId): void {
        DB::table('idn_roles')->insert([
            'id' => $reviewerRoleId,
            'tenant_id' => $tenantId,
            'key' => 'facility_reviewer',
            'name' => 'Facility Reviewer',
            'status' => 'ACTIVE',
            'scope_type' => 'FACILITY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['admin.workspace.access', 'identity.assignments.manage', 'identity.users.read'] as $permission) {
            $permissionId = DB::table('idn_permissions')->where('key', $permission)->value('id');
            if (! is_string($permissionId)) {
                $permissionId = (string) Str::uuid7();
                DB::table('idn_permissions')->insert([
                    'id' => $permissionId,
                    'key' => $permission,
                    'layer' => 'ACTION',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'role_id' => $reviewerRoleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $reviewer->getKey(),
            'role_id' => $reviewerRoleId,
            'facility_id' => $facilityId,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($reviewer)
        ->getJson(route('hr.people.search', ['q' => 'ReviewSearch']))
        ->assertOk()
        ->assertJsonPath('results.0.type', 'employee')
        ->assertJsonPath('results.0.id', $staffId)
        ->assertJsonPath('results.0.photoUrl', null)
        ->assertJsonPath('results.0.href', route('hr.staff.show', ['staff' => $staffId]));

    $this->actingAs($reviewer)
        ->get(route('hr.staff.show', $staffId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Show')
            ->where('staff.name', 'Baraka K ReviewSearch'));

    // A stored person photo is exposed as an authorized photo URL …
    TenantContextScope::run($tenantId, $facilityId, function () use ($personId): void {
        DB::table('hr_persons')->where('id', $personId)->update(['photo_document_id' => (string) Str::uuid7()]);
    });

    $this->actingAs($reviewer)
        ->getJson(route('hr.people.search', ['q' => 'ReviewSearch']))
        ->assertOk()
        ->assertJsonPath('results.0.photoUrl', route('hr.staff.photo', ['staff' => $staffId]));

    // … and the reviewer gate admits the photo endpoint itself (404 here
    // proves the gate passes — the fake document simply has no stream).
    $this->actingAs($reviewer)
        ->get(route('hr.staff.photo', ['staff' => $staffId]))
        ->assertNotFound();

    // An actor with a facility assignment but no permissions is still denied.
    $outsider = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $emptyRoleId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $outsider, $emptyRoleId): void {
        DB::table('idn_roles')->insert([
            'id' => $emptyRoleId,
            'tenant_id' => $tenantId,
            'key' => 'empty_role',
            'name' => 'Empty Role',
            'status' => 'ACTIVE',
            'scope_type' => 'FACILITY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $outsider->getKey(),
            'role_id' => $emptyRoleId,
            'facility_id' => $facilityId,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($outsider)
        ->get(route('hr.staff.photo', ['staff' => $staffId]))
        ->assertForbidden();
});

it('creates a staff record, requires facility-admin confirmation, and links only an existing account', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $requester = $fixture['user'];
    $this->actingAs($requester)
        ->post(route('hr.staff.cadres.store'), [
            'code' => 'NURSE',
            'name' => 'Registered Nurse',
            'category' => 'NURSING',
        ])
        ->assertRedirect(route('hr.staff.positions'));
    $cadreId = TenantContextScope::run($tenantId, null, fn (): string => (string) DB::table('hr_cadres')
        ->where('tenant_id', $tenantId)
        ->where('code', 'NURSE')
        ->value('id'));
    $this->actingAs($requester)
        ->post(route('hr.staff.positions.store'), [
            'code' => 'RN-01',
            'title' => 'Registered Nurse',
            'cadre_id' => $cadreId,
        ])
        ->assertRedirect(route('hr.staff.positions'));
    $positionId = TenantContextScope::run($tenantId, null, fn (): string => (string) DB::table('hr_positions')
        ->where('tenant_id', $tenantId)
        ->where('code', 'RN-01')
        ->value('id'));

    $this->actingAs($requester)
        ->post(route('hr.staff.store'), [
            'first_name' => 'Amina',
            'middle_name' => '',
            'last_name' => 'Mrema',
            'date_of_birth' => '1990-04-12',
            'gender' => 'FEMALE',
            'phone' => '+255754000111',
            'email' => 'amina.staff@example.test',
            'department_id' => '',
            'unit_id' => '',
            'effective_from' => now()->toDateString(),
            'position_id' => $positionId,
            'contract_type' => 'PERMANENT',
            'employment_start_date' => now()->toDateString(),
            'fte' => '1.00',
        ])
        ->assertRedirect(route('hr.staff.index'));

    $assignmentRequest = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('hr_assignment_requests')
        ->where('tenant_id', $tenantId)
        ->where('facility_id', $facilityId)
        ->where('requested_by', $requester->getKey())
        ->first());
    expect($assignmentRequest)->not->toBeNull()
        ->and($assignmentRequest->status)->toBe('PENDING')
        ->and(TenantContextScope::run($tenantId, $facilityId, fn (): bool => DB::table('hr_assignments')
            ->where('staff_id', $assignmentRequest->staff_id)
            ->exists()))->toBeFalse()
        ->and(TenantContextScope::run($tenantId, $facilityId, fn (): ?string => DB::table('hr_staff')
            ->where('id', $assignmentRequest->staff_id)
            ->value('primary_position_id')))->toBe($positionId)
        ->and(TenantContextScope::run($tenantId, $facilityId, fn (): ?string => DB::table('hr_employments')
            ->where('staff_id', $assignmentRequest->staff_id)
            ->value('contract_type')))->toBe('PERMANENT');

    $this->actingAs($requester)
        ->get(route('hr.staff.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Index'));

    $approver = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $approver->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();
    $approverRoleId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $approver, $approverRoleId): void {
        DB::table('idn_roles')->insert([
            'id' => $approverRoleId,
            'tenant_id' => $tenantId,
            'key' => 'facility_assignment_approver',
            'name' => 'Facility Assignment Approver',
            'status' => 'ACTIVE',
            'scope_type' => 'FACILITY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['admin.workspace.access', 'identity.assignments.manage'] as $permission) {
            $permissionId = DB::table('idn_permissions')->where('key', $permission)->value('id');
            if (! is_string($permissionId)) {
                $permissionId = (string) Str::uuid7();
                DB::table('idn_permissions')->insert([
                    'id' => $permissionId,
                    'key' => $permission,
                    'layer' => 'ACTION',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'role_id' => $approverRoleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $approver->getKey(),
            'role_id' => $approverRoleId,
            'facility_id' => $facilityId,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($approver)
        ->get(route('hr.staff.assignment-requests'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/AssignmentRequests')
            ->where('canReviewAssignments', true)
            ->where('pendingRequests.0.name', 'Amina Mrema')
            ->where('pendingRequests.0.staffNumber', 'STF-00000001'));

    $this->actingAs($approver)
        ->get(route('hr.staff.onboarding', ['focus' => 'placements']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Onboarding')
            ->where('focus', 'placements')
            ->where('rows.0.name', 'Amina Mrema')
            ->where('rows.0.placement.state', 'PENDING')
            ->where('rows.0.nextAction.kind', 'CONFIRM_PLACEMENT')
            ->where('summary.placements', 1)
            ->where('permissions.canReviewPlacements', true));

    $this->actingAs($approver)
        ->post(route('hr.staff.assignment-requests.decide', $assignmentRequest->id), [
            'decision' => 'approve',
        ])
        ->assertRedirect(route('hr.staff.assignment-requests'));

    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_assignment_requests')
        ->where('id', $assignmentRequest->id)
        ->value('status')))->toBe('APPROVED');

    $this->actingAs($approver)
        ->get(route('hr.staff.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Index')
            ->where('activeStatus', 'ALL')
            ->where('staff.0.name', 'Amina Mrema')
            ->where('statusCounts.PROSPECT', 1));

    $this->actingAs($approver)
        ->get(route('hr.staff.index', ['status' => 'ACTIVE']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Index')
            ->where('activeStatus', 'ALL')
            ->where('staff.0.name', 'Amina Mrema'));

    $this->actingAs($approver)
        ->get(route('hr.staff.index', ['status' => 'PROSPECT']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Index')
            ->where('activeStatus', 'PROSPECT')
            ->where('staff.0.name', 'Amina Mrema'));
    $this->actingAs($approver)
        ->get(route('hr.staff.show', $assignmentRequest->staff_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Show')
            ->where('staff.name', 'Amina Mrema')
            ->where('staff.hasCurrentFacilityAssignment', true)
            ->where('canManagePerson', false)
            ->where('canManageEmployment', false));

    $linkedAccount = User::factory()->forTenant($tenantId)->create([
        'email' => 'amina.account@example.test',
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    $assignmentsBeforeLink = TenantContextScope::run($tenantId, null, fn (): int => DB::table('idn_access_assignments')
        ->where('tenant_id', $tenantId)
        ->where('user_id', $linkedAccount->getKey())
        ->count());

    $this->actingAs($requester)
        ->post(route('hr.staff.link', $assignmentRequest->staff_id), [
            'email' => $linkedAccount->email,
        ])
        ->assertRedirect(route('hr.staff.show', $assignmentRequest->staff_id));

    expect(TenantContextScope::run($tenantId, null, fn (): ?string => DB::table('hr_staff_user_links')
        ->where('tenant_id', $tenantId)
        ->where('staff_id', $assignmentRequest->staff_id)
        ->value('user_id')))->toBe($linkedAccount->getKey())
        ->and(TenantContextScope::run($tenantId, null, fn (): int => DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $linkedAccount->getKey())
            ->count()))->toBe($assignmentsBeforeLink);

    $this->actingAs($requester)
        ->get(route('hr.staff.index', ['status' => 'PROSPECT']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Index')
            ->where('staff.0.name', 'Amina Mrema')
            ->where('staff.0.positionTitle', 'Registered Nurse'));

    $this->actingAs($requester)
        ->get(route('hr.staff.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Create')
            ->where('facility.id', $facilityId)
            ->where('canManageEmployment', true));

    $this->actingAs($requester)
        ->get(route('hr.staff.show', $assignmentRequest->staff_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Show')
            ->where('staff.staffNumber', 'STF-00000001')
            ->where('staff.employment.status', 'DRAFT')
            ->where('canManagePerson', true));

    $this->actingAs($requester)
        ->get(route('hr.staff.assignment-requests'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/AssignmentRequests')
            ->where('canManageStaff', true));

    $this->actingAs($requester)
        ->get(route('hr.staff.positions'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Positions')
            ->where('cadres.0.name', 'Registered Nurse')
            ->where('positions.0.title', 'Registered Nurse'));

    $this->actingAs($requester)
        ->put(route('hr.staff.person.update', $assignmentRequest->staff_id), [
            'first_name' => 'Amina',
            'middle_name' => 'Juma',
            'last_name' => 'Mrema',
            'date_of_birth' => '1990-04-12',
            'gender' => 'FEMALE',
            'national_id' => 'NIDA-12345',
            'phone' => '+255754000222',
            'email' => 'amina.updated@example.test',
            'physical_address' => 'Dar es Salaam',
        ])
        ->assertRedirect(route('hr.staff.show', $assignmentRequest->staff_id));
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_persons')
        ->where('id', DB::table('hr_staff')->where('id', $assignmentRequest->staff_id)->value('person_id'))
        ->value('email')))->toBe('amina.updated@example.test');

    $this->actingAs($requester)
        ->post(route('hr.staff.onboard', $assignmentRequest->staff_id), [
            'joined_date' => now()->toDateString(),
        ])
        ->assertRedirect(route('hr.staff.show', $assignmentRequest->staff_id));
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_staff')
        ->where('id', $assignmentRequest->staff_id)
        ->value('status')))->toBe('ACTIVE');

    $employmentId = TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_employments')
        ->where('staff_id', $assignmentRequest->staff_id)
        ->value('id'));
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_employments')
        ->where('id', $employmentId)
        ->value('status')))->toBe('ACTIVE');
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): bool => DB::table('shared_audit_log')
        ->where('tenant_id', $tenantId)
        ->where('facility_id', $facilityId)
        ->where('action', 'hr.employment.started')
        ->where('target_id', $employmentId)
        ->exists()))->toBeTrue();

    $staffId = (string) $assignmentRequest->staff_id;
    $this->actingAs($requester)
        ->post(route('hr.staff.suspend', $staffId), ['reason' => 'Administrative review'])
        ->assertRedirect(route('hr.staff.show', $staffId));
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_staff')
        ->where('id', $staffId)
        ->value('status')))->toBe('SUSPENDED');

    $this->actingAs($requester)
        ->from(route('hr.staff.show', $staffId))
        ->post(route('hr.staff.suspend', $staffId), ['reason' => 'Duplicate suspension'])
        ->assertRedirect(route('hr.staff.show', $staffId))
        ->assertSessionHasErrors('lifecycle');

    $this->actingAs($requester)
        ->post(route('hr.staff.reinstate', $staffId))
        ->assertRedirect(route('hr.staff.show', $staffId));
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_staff')
        ->where('id', $staffId)
        ->value('status')))->toBe('ACTIVE');

    $this->actingAs($requester)
        ->from(route('hr.staff.show', $staffId))
        ->post(route('hr.staff.terminate', $staffId), [
            'terminated_date' => now()->toDateString(),
            'reason' => 'Resignation',
        ])
        ->assertRedirect(route('hr.staff.show', $staffId))
        ->assertSessionHasErrors('lifecycle');
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_staff')
        ->where('id', $staffId)
        ->value('status')))->toBe('ACTIVE');

    $assignmentId = TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_assignments')
        ->where('staff_id', $staffId)
        ->where('status', 'ACTIVE')
        ->value('id'));
    TenantContextScope::run($tenantId, $facilityId, function () use ($assignmentId, $employmentId): void {
        app(CommandBus::class)->dispatch(new EndAssignment(
            assignmentId: $assignmentId,
            endedDate: now()->toDateString(),
            reason: 'Employment ended',
        ));
        app(CommandBus::class)->dispatch(new EndEmployment(
            employmentId: $employmentId,
            endDate: now()->toDateString(),
            reason: 'Employment ended',
        ));
    });

    $this->actingAs($requester)
        ->post(route('hr.staff.terminate', $staffId), [
            'terminated_date' => now()->toDateString(),
            'reason' => 'Resignation',
        ])
        ->assertRedirect(route('hr.staff.show', $staffId));
    $terminatedStaff = TenantContextScope::run($tenantId, $facilityId, fn (): object => DB::table('hr_staff')
        ->where('id', $staffId)
        ->first());
    expect($terminatedStaff->status)->toBe('TERMINATED')
        ->and($terminatedStaff->termination_reason)->toBe('Resignation')
        ->and(TenantContextScope::run($tenantId, $facilityId, fn (): int => DB::table('shared_audit_log')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('action', 'hr.staff.terminated')
            ->where('target_id', $staffId)
            ->count()))->toBe(1);

    $this->actingAs($requester)
        ->get(route('hr.staff.show', $staffId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Show')
            ->where('staff.status', 'TERMINATED')
            ->where('staff.terminatedDate', now()->toDateString()));
});

it('leaves a future-dated draft employment for the separate start action during onboarding', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $cadreId = defineCadreHelper($tenantId, facilityId: $facilityId);
    $positionId = definePositionHelper($tenantId, $cadreId, facilityId: $facilityId);
    $personId = createPersonHelper($tenantId, 'Future', 'D', 'Start', facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, $cadreId, $positionId, $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);
    $employmentId = createEmploymentHelper(
        $tenantId,
        $staffId,
        $positionId,
        startDate: now()->addMonth()->toDateString(),
        facilityId: $facilityId,
    );

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.onboard', $staffId), [
            'joined_date' => now()->toDateString(),
        ])
        ->assertRedirect(route('hr.staff.show', $staffId));

    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_staff')
        ->where('id', $staffId)
        ->value('status')))->toBe('ACTIVE')
        ->and(TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_employments')
            ->where('id', $employmentId)
            ->value('status')))->toBe('DRAFT');
});

it('offers the pharmacist role for system access when the facility has the pharmacy capability', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $this->actingAs($fixture['user'])
        ->get(route('hr.staff.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('invitationRoles', 5)
            ->where('invitationRoles.0.key', 'nurse')
            ->where('invitationRoles.1.key', 'doctor'));

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
        ->get(route('hr.staff.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('invitationRoles', 6)
            ->where('invitationRoles.2.key', 'pharmacist')
            ->where('invitationRoles.2.label', 'Pharmacist')
            ->where('invitationRoles.2.requiredCapability', 'PHARMACY'));
});

it('allows the requester to correct and resubmit a rejected assignment for independent review', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $requester = $fixture['user'];
    $cadreId = defineCadreHelper($tenantId);
    $positionId = definePositionHelper($tenantId, $cadreId);
    $personId = createPersonHelper($tenantId, 'Rashid', null, 'Mrema');
    $staffId = createStaffHelper($tenantId, $personId, $cadreId, $positionId);
    $departmentId = (string) Str::uuid7();
    $requestId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, function () use (
        $tenantId,
        $facilityId,
        $departmentId,
        $requestId,
        $staffId,
        $requester,
    ): void {
        DB::table('org_departments')->insert([
            'id' => $departmentId,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'name' => 'Outpatient',
            'code' => 'OPD',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('hr_assignment_requests')->insert([
            'id' => $requestId,
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'facility_id' => $facilityId,
            'scope' => 'FACILITY',
            'effective_from' => now()->toDateString(),
            'requested_by' => $requester->getKey(),
            'status' => 'REJECTED',
            'decided_by' => (string) Str::uuid7(),
            'decided_at' => now(),
            'decision_reason' => 'Choose a department before resubmitting.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($requester)
        ->post(route('hr.staff.assignment-requests.resubmit', $requestId), [
            'department_id' => $departmentId,
            'unit_id' => '',
            'effective_from' => now()->addDay()->toDateString(),
        ])
        ->assertRedirect(route('hr.staff.assignment-requests'));

    $resubmitted = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('hr_assignment_requests')
        ->where('id', $requestId)
        ->first());
    expect($resubmitted->status)->toBe('PENDING')
        ->and($resubmitted->department_id)->toBe($departmentId)
        ->and($resubmitted->scope)->toBe('DEPARTMENT')
        ->and($resubmitted->decided_by)->toBeNull()
        ->and($resubmitted->decision_reason)->toBeNull();

    $this->actingAs($requester)
        ->get(route('hr.staff.assignment-requests'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rejectedRequests', []));
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_assignment_requests')
        ->where('id', $requestId)
        ->value('status')))->toBe('PENDING');
});

it('allows HR staff to confirm their own facility assignment request', function (): void {
    $fixture = hrFixture([
        'hr.access',
        'hr.person.manage',
        'hr.staff.read',
        'hr.staff.manage',
        'hr.assignment.manage',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $staffId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $fixture, $staffId): void {
        $personId = createPersonHelper($tenantId, 'Noah', null, 'SelfReview');
        app(CommandBus::class)->dispatch(new CreateStaff(
            personId: $personId,
            staffId: $staffId,
        ));
        DB::table('hr_assignment_requests')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'facility_id' => $facilityId,
            'scope' => 'FACILITY',
            'effective_from' => now()->toDateString(),
            'requested_by' => $fixture['user']->getKey(),
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $requestId = TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_assignment_requests')
        ->where('staff_id', $staffId)
        ->value('id'));

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.assignment-requests.decide', $requestId), [
            'decision' => 'approve',
        ])
        ->assertRedirect(route('hr.staff.assignment-requests'));

    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_assignment_requests')
        ->where('id', $requestId)
        ->value('status')))->toBe('APPROVED')
        ->and(TenantContextScope::run($tenantId, $facilityId, fn (): bool => DB::table('hr_assignments')
            ->where('staff_id', $staffId)
            ->where('facility_id', $facilityId)
            ->exists()))->toBeTrue();
});

it('reconciles the protected staff-manager permissions for existing assignments', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $roleId = TenantContextScope::run($tenantId, $facilityId, fn (): string => app(FacilityStaffManagerRoleProvisioner::class)
        ->provision($tenantId, (string) $fixture['user']->getKey(), $facilityId));

    TenantContextScope::run($tenantId, $facilityId, function () use ($fixture, $roleId): void {
        DB::table('idn_access_assignments')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('user_id', $fixture['user']->getKey())
            ->update(['role_id' => $roleId]);
        $permissionId = DB::table('idn_permissions')->where('key', 'hr.cadre_position.manage')->value('id');
        DB::table('idn_permission_role')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->delete();
    });

    $this->actingAs($fixture['user'])
        ->get(route('hr.staff.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('HR/Staff/Index'));

    expect(TenantContextScope::run($tenantId, $facilityId, fn (): bool => DB::table('idn_permission_role as assignment')
        ->join('idn_permissions as permission', 'permission.id', '=', 'assignment.permission_id')
        ->where('assignment.tenant_id', $tenantId)
        ->where('assignment.role_id', $roleId)
        ->where('permission.key', 'hr.cadre_position.manage')
        ->exists()))->toBeTrue();
});

it('creates and links a staff account invitation in the staff workflow while leaving role approval pending', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    Mail::fake();

    $response = $this->actingAs($fixture['user'])
        ->post(route('hr.staff.store'), [
            'first_name' => 'Nuru',
            'middle_name' => '',
            'last_name' => 'Nurse',
            'date_of_birth' => '1990-04-12',
            'gender' => 'FEMALE',
            'phone' => '+255754000111',
            'email' => 'nuru@example.test',
            'request_login' => true,
            'primary_role_key' => 'nurse',
            'secondary_role_keys' => ['receptionist'],
            'change_reference' => 'HR-INVITE-101',
            'access_reason' => 'Provide nursing care at this facility.',
            'department_id' => '',
            'unit_id' => '',
            'effective_from' => now()->toDateString(),
            'position_id' => '',
            'contract_type' => '',
            'employment_start_date' => '',
            'fte' => '1.00',
        ]);
    $staffId = TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('hr_staff')
        ->join('hr_persons', 'hr_persons.id', '=', 'hr_staff.person_id')
        ->where('hr_staff.tenant_id', $tenantId)
        ->where('hr_persons.first_name', 'Nuru')
        ->where('hr_persons.last_name', 'Nurse')
        ->value('hr_staff.id'));
    $response->assertRedirect(route('hr.staff.show', ['staff' => $staffId, 'section' => 'access']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', fn (string $status): bool => str_starts_with($status, 'Staff created; placement pending confirmation.')
            && str_contains($status, 'Access approval may be required.'));

    $this->actingAs($fixture['user'])
        ->get(route('hr.staff.show', ['staff' => $staffId, 'section' => 'access']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staff.id', $staffId)
            ->where('staff.hasCurrentFacilityAssignment', false)
            ->where('staff.pendingAssignmentEffectiveFrom', now()->toDateString()));

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId): void {
        $invitation = DB::table('aut_admin_bootstrap_tokens as token')
            ->join('users', 'users.id', '=', 'token.user_id')
            ->where('token.tenant_id', $tenantId)
            ->where('token.purpose', 'FACILITY_ROLE')
            ->where('token.role_key', 'nurse')
            ->where('users.email', 'nuru@example.test')
            ->first(['token.user_id', 'token.role_keys', 'users.name']);
        expect($invitation)->not->toBeNull()
            ->and($invitation->name)->toBe('Nuru Nurse')
            ->and(json_decode($invitation->role_keys, true))->toBe(['nurse', 'receptionist'])
            ->and(DB::table('idn_roles')
                ->where('tenant_id', $tenantId)
                ->whereIn('key', ['nurse', 'receptionist'])
                ->where('status', 'ACTIVE')
                ->where('scope_type', 'FACILITY')
                ->where('is_protected', true)
                ->count())->toBe(2)
            ->and(DB::table('hr_staff_user_links as staff_link')
                ->join('hr_staff as staff', 'staff.id', '=', 'staff_link.staff_id')
                ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
                ->where('staff_link.tenant_id', $tenantId)
                ->where('staff_link.user_id', $invitation->user_id)
                ->where('person.first_name', 'Nuru')
                ->where('person.last_name', 'Nurse')
                ->exists())->toBeTrue()
            ->and(DB::table('idn_access_assignments')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $invitation->user_id)
                ->count())->toBe(0);
    });
});
