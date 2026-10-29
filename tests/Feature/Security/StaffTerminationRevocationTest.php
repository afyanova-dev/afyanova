<?php

declare(strict_types=1);

use App\Domains\HR\Application\Command\EndAssignment;
use App\Domains\HR\Application\Command\OnboardStaff;
use App\Domains\Identity\Application\LinkedStaffGrantEligibility;
use App\Domains\Organization\Domain\Model\Facility;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../tests/Support/hr.php';

if (! function_exists('tmrEnsureNurseRole')) {
    function tmrEnsureNurseRole(string $tenantId): string
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
}

if (! function_exists('tmrSelectFacility')) {
    function tmrSelectFacility(object $test, string $facilityId): void
    {
        $test->post(route('facility-context.update'), ['facility_id' => $facilityId])->assertRedirect();
    }
}

if (! function_exists('tmrEnsureReceptionistRole')) {
    function tmrEnsureReceptionistRole(string $tenantId): string
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
}

if (! function_exists('tmrEnsureTenantAdminRole')) {
    function tmrEnsureTenantAdminRole(string $tenantId): string
    {
        return TenantContextScope::run($tenantId, null, function () use ($tenantId): string {
            $existing = DB::table('idn_roles')->where('tenant_id', $tenantId)->where('key', 'tenant_admin')->value('id');
            if (is_string($existing)) {
                return $existing;
            }
            $roleId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $roleId, 'tenant_id' => $tenantId, 'key' => 'tenant_admin',
                'name' => 'Tenant Admin', 'status' => 'ACTIVE', 'scope_type' => 'ORG',
                'is_protected' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['admin.workspace.access', 'audit.events.read'] as $key) {
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

            return $roleId;
        });
    }
}

if (! function_exists('tmrEnsureFacilityAdminRole')) {
    function tmrEnsureFacilityAdminRole(string $tenantId): string
    {
        return TenantContextScope::run($tenantId, null, function () use ($tenantId): string {
            $existing = DB::table('idn_roles')
                ->where('tenant_id', $tenantId)
                ->where('key', 'facility_admin')
                ->value('id');
            if (is_string($existing)) {
                return $existing;
            }

            $roleId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $roleId,
                'tenant_id' => $tenantId,
                'key' => 'facility_admin',
                'name' => 'Facility Admin',
                'status' => 'ACTIVE',
                'scope_type' => 'FACILITY',
                'is_protected' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $roleId;
        });
    }
}

it('revokes all facility access tenant-wide when linked staff is terminated', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityA = (string) $fixture['facility']->id;
    tmrSelectFacility($this, $facilityA);

    $facilityB = TenantContextScope::run($tenantId, null, function () use ($tenantId, $fixture): string {
        /** @var Facility $facility */
        $facility = Facility::factory()->forTenant($tenantId, $fixture['organization']->id)->create([
            'name' => 'Second Clinic',
        ]);

        // Give the HR actor scope at the second facility so HR placement
        // setup commands (assign/end) authorize there. Production actors
        // would hold equivalent multi-facility HR scope.
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'user_id' => $fixture['user']->getKey(), 'role_id' => $fixture['role_id'],
            'facility_id' => $facility->id, 'status' => 'ACTIVE',
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (string) $facility->id;
    });

    $nurseRoleId = tmrEnsureNurseRole($tenantId);

    // Staff with HR placements at both facilities, onboarded, linked to a user
    // holding ACTIVE identity assignments at both facilities.
    $personId = createPersonHelper($tenantId, 'Tumaini', 'K', 'Multi', facilityId: $facilityA);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityA);
    $hrAssignmentA = assignStaffHelper($tenantId, $staffId, $facilityA);
    $hrAssignmentB = assignStaffHelper($tenantId, $staffId, $facilityB);
    TenantContextScope::run($tenantId, $facilityA, function () use ($staffId): void {
        app(CommandBus::class)->dispatch(new OnboardStaff($staffId, '2026-01-05'));
    });
    $linked = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityA, $facilityB, $linked, $nurseRoleId): void {
        foreach ([$facilityA, $facilityB] as $index => $facilityId) {
            DB::table('idn_access_assignments')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
                'role_id' => $nurseRoleId, 'facility_id' => $facilityId, 'status' => 'ACTIVE',
                'is_primary' => $index === 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('aut_sessions')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'session_id' => 'sess-multi-1', 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
            'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    linkStaffUserHelper($tenantId, $staffId, (string) $linked->getKey(), $facilityA);

    // Unrelated same-tenant user and other-tenant user must remain unaffected.
    $unrelated = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);
    $otherTenant = makeTenantWithUser(['patient.patients.read']);
    $otherTenantId = (string) $otherTenant['tenant']->id;
    $otherUser = User::factory()->forTenant($otherTenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);
    $otherNurseRoleId = tmrEnsureNurseRole($otherTenantId);
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityA, $unrelated, $nurseRoleId): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $unrelated->getKey(),
            'role_id' => $nurseRoleId, 'facility_id' => $facilityA, 'status' => 'ACTIVE',
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    TenantContextScope::run($otherTenantId, null, function () use ($otherTenantId, $otherTenant, $otherUser, $otherNurseRoleId): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $otherTenantId, 'user_id' => $otherUser->getKey(),
            'role_id' => $otherNurseRoleId, 'facility_id' => $otherTenant['facility']->id, 'status' => 'ACTIVE',
            'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    // End both HR placements so termination preconditions pass.
    foreach ([$hrAssignmentA, $hrAssignmentB] as $hrAssignment) {
        TenantContextScope::run($tenantId, $facilityA, function () use ($hrAssignment): void {
            app(CommandBus::class)->dispatch(new EndAssignment($hrAssignment, CarbonImmutable::parse('2026-02-01'), 'Placement ended.'));
        });
    }

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.terminate', $staffId), [
            'terminated_date' => '2026-03-01',
            'reason' => 'End of contract departure.',
        ])->assertRedirect();

    TenantContextScope::run($tenantId, null, function () use ($staffId): void {
        // HR history preserved.
        expect(DB::table('hr_staff')->where('id', $staffId)->value('status'))->toBe('TERMINATED');
        expect(DB::table('hr_staff')->where('id', $staffId)->value('termination_reason'))->toBe('End of contract departure.');
    });

    TenantContextScope::run($tenantId, null, function () use (
        $tenantId, $facilityA, $facilityB, $linked, $staffId, $unrelated
    ): void {
        // Both facility assignments revoked (rows preserved, status terminal).
        $statuses = DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)->where('user_id', $linked->getKey())
            ->whereIn('facility_id', [$facilityA, $facilityB])
            ->pluck('status')->map(static fn ($s): string => (string) $s)->all();
        expect($statuses)->toHaveCount(2);
        foreach ($statuses as $status) {
            expect($status)->toBe('REVOKED');
        }

        // Access denied at both facilities via the same ScopeChecker path the
        // application uses (ACTIVE-only, no HR-status check).
        expect(app(ScopeChecker::class)->effectivePermissions($linked, $facilityA))->not->toContain('patient.patients.read');
        expect(app(ScopeChecker::class)->effectivePermissions($linked, $facilityB))->not->toContain('patient.patients.read');

        // Device sessions revoked.
        expect(DB::table('aut_sessions')->where('tenant_id', $tenantId)->where('user_id', $linked->getKey())
            ->whereNull('revoked_at')->exists())->toBeFalse();

        // Per-facility audit rows preserve target-facility attribution.
        foreach ([$facilityA, $facilityB] as $facilityId) {
            expect(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
                ->where('action', 'identity.access.revoked')->where('facility_id', $facilityId)
                ->where('target_id', $linked->getKey())->exists())->toBeTrue();
        }
        expect(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
            ->where('action', 'hr.staff.terminated')->where('target_id', $staffId)->exists())->toBeTrue();

        // Unrelated same-tenant user untouched.
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $unrelated->getKey())->where('facility_id', $facilityA)->value('status'))->toBe('ACTIVE');

        // Other tenant untouched (checked in its own context below).
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->whereNotNull('facility_id')->where('status', 'ACTIVE')->exists())->toBeFalse();
    });

    TenantContextScope::run($otherTenantId, null, function () use ($otherTenantId, $otherUser): void {
        expect(DB::table('idn_access_assignments')->where('tenant_id', $otherTenantId)
            ->where('user_id', $otherUser->getKey())->value('status'))->toBe('ACTIVE');
    });
});

it('terminates staff without a linked user without touching identity', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    tmrSelectFacility($this, $facilityId);

    $personId = createPersonHelper($tenantId, 'No', 'K', 'Link', facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    $hrAssignment = assignStaffHelper($tenantId, $staffId, $facilityId);
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        app(CommandBus::class)->dispatch(new OnboardStaff($staffId, '2026-01-05'));
    });
    TenantContextScope::run($tenantId, $facilityId, function () use ($hrAssignment): void {
        app(CommandBus::class)->dispatch(new EndAssignment($hrAssignment, CarbonImmutable::parse('2026-02-01'), 'Placement ended.'));
    });

    $assignmentsBefore = TenantContextScope::run($tenantId, null, fn (): int => DB::table('idn_access_assignments')
        ->where('tenant_id', $tenantId)->count());

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.terminate', $staffId), [
            'terminated_date' => '2026-03-01',
            'reason' => 'End of contract departure.',
        ])->assertRedirect();

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $staffId, $assignmentsBefore): void {
        expect(DB::table('hr_staff')->where('id', $staffId)->value('status'))->toBe('TERMINATED');
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)->count())->toBe($assignmentsBefore);
        expect(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
            ->where('action', 'hr.staff.terminated')->where('target_id', $staffId)->exists())->toBeTrue();
    });
});

it('leaves HR and identity unchanged when termination preconditions fail', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    tmrSelectFacility($this, $facilityId);
    $nurseRoleId = tmrEnsureNurseRole($tenantId);

    $personId = createPersonHelper($tenantId, 'Active', 'K', 'Staff', facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        app(CommandBus::class)->dispatch(new OnboardStaff($staffId, '2026-01-05'));
    });
    $linked = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $linked, $nurseRoleId): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $nurseRoleId, 'facility_id' => $facilityId, 'status' => 'ACTIVE',
            'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    linkStaffUserHelper($tenantId, $staffId, (string) $linked->getKey(), $facilityId);

    // ACTIVE HR placement still present: termination must be refused atomically.
    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.terminate', $staffId), [
            'terminated_date' => '2026-03-01',
            'reason' => 'Attempt with open placement.',
        ])->assertSessionHasErrors('lifecycle');

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $linked, $staffId): void {
        expect(DB::table('hr_staff')->where('id', $staffId)->value('status'))->toBe('ACTIVE');
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('facility_id', $facilityId)->value('status'))->toBe('ACTIVE');
        expect(app(ScopeChecker::class)->effectivePermissions($linked, $facilityId))->toContain('patient.patients.read');
    });
});

it('still requires hr.staff.manage to terminate staff', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    tmrSelectFacility($this, $facilityId);

    $personId = createPersonHelper($tenantId, 'Unauth', 'K', 'User', facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);

    $viewer = makeTenantWithUser(['hr.staff.read']);
    // Move viewer into the same tenant/facility scope for a pure permission test.
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $viewer): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $viewer['user']->getKey(),
            'role_id' => $viewer['role_id'], 'facility_id' => $facilityId, 'status' => 'ACTIVE',
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $this->actingAs($viewer['user'])
        ->post(route('hr.staff.terminate', $staffId), [
            'terminated_date' => '2026-03-01',
            'reason' => 'Unauthorized attempt.',
        ])->assertForbidden();
});

it('routes tenant admin removal from HR termination through independent approval', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $organizationId = (string) $fixture['organization']->id;
    $facilityId = (string) $fixture['facility']->id;
    tmrSelectFacility($this, $facilityId);
    $nurseRoleId = tmrEnsureNurseRole($tenantId);
    $tenantAdminRoleId = tmrEnsureTenantAdminRole($tenantId);
    $facilityAdminRoleId = tmrEnsureFacilityAdminRole($tenantId);

    $personId = createPersonHelper($tenantId, 'Omar', 'K', 'Org', facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    $hrAssignment = assignStaffHelper($tenantId, $staffId, $facilityId);
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        app(CommandBus::class)->dispatch(new OnboardStaff($staffId, '2026-01-05'));
    });
    $linked = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);
    TenantContextScope::run($tenantId, null, function () use (
        $tenantId, $organizationId, $facilityId, $linked, $nurseRoleId, $tenantAdminRoleId, $facilityAdminRoleId
    ): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $nurseRoleId, 'facility_id' => $facilityId, 'status' => 'ACTIVE',
            'organization_id' => $organizationId,
            'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $tenantAdminRoleId, 'organization_id' => $organizationId, 'facility_id' => null,
            'status' => 'ACTIVE', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $facilityAdminRoleId, 'organization_id' => $organizationId, 'facility_id' => $facilityId,
            'status' => 'ACTIVE', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('aut_sessions')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'session_id' => 'sess-org-1', 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
            'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    linkStaffUserHelper($tenantId, $staffId, (string) $linked->getKey(), $facilityId);
    TenantContextScope::run($tenantId, $facilityId, function () use ($hrAssignment): void {
        app(CommandBus::class)->dispatch(new EndAssignment($hrAssignment, CarbonImmutable::parse('2026-02-01'), 'Placement ended.'));
    });

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.terminate', $staffId), [
            'terminated_date' => '2026-03-01',
            'reason' => 'End of contract departure.',
        ])->assertRedirect();

    TenantContextScope::run($tenantId, null, function () use (
        $tenantId, $organizationId, $facilityId, $linked, $staffId, $nurseRoleId, $tenantAdminRoleId,
        $facilityAdminRoleId
    ): void {
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('role_id', $nurseRoleId)->value('status'))->toBe('REVOKED');
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('role_id', $tenantAdminRoleId)->value('status'))->toBe('ACTIVE');
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('role_id', $facilityAdminRoleId)->value('status'))->toBe('ACTIVE');
        // Assignments remain intact until independent approval, while HR status blocks the account.
        expect(app(ScopeChecker::class)->effectiveOrganizationPermissions($linked, $organizationId))->toContain('admin.workspace.access');
        expect(app(LinkedStaffGrantEligibility::class)
            ->isBlockedByHrLifecycle((string) $linked->tenant_id, (string) $linked->getKey()))->toBeTrue();
        expect(DB::table('idn_admin_role_promotion_requests')->where('tenant_id', $tenantId)
            ->where('target_user_id', $linked->getKey())->where('role_key', 'tenant_admin')
            ->where('action', 'REVOKE')->where('request_origin', 'HR_LIFECYCLE')
            ->where('hr_staff_id', $staffId)->where('status', 'PENDING')->exists())->toBeTrue();
        expect(DB::table('idn_admin_role_promotion_requests')->where('tenant_id', $tenantId)
            ->where('target_user_id', $linked->getKey())->where('role_key', 'facility_admin')
            ->where('facility_id', $facilityId)->where('action', 'REVOKE')
            ->where('request_origin', 'HR_LIFECYCLE')->where('hr_staff_id', $staffId)
            ->where('status', 'PENDING')->exists())->toBeTrue();

        // Sessions invalidated and both scopes audited (org row has null facility).
        expect(DB::table('aut_sessions')->where('tenant_id', $tenantId)->where('user_id', $linked->getKey())
            ->whereNull('revoked_at')->exists())->toBeFalse();
        expect(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
            ->where('action', 'identity.access.revoked')->where('facility_id', $facilityId)
            ->where('target_id', $linked->getKey())->exists())->toBeTrue();
        expect(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
            ->where('action', 'identity.admin_role_promotion.revocation_requested')
            ->whereNull('facility_id')->where('target_id', DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)->where('user_id', $linked->getKey())
            ->where('role_id', $tenantAdminRoleId)->value('id'))->exists())->toBeTrue();
        expect(DB::table('hr_staff')->where('id', $staffId)->value('status'))->toBe('TERMINATED');
    });

    $approver = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $requestId = TenantContextScope::run($tenantId, null, function () use (
        $tenantId, $organizationId, $approver, $tenantAdminRoleId, $staffId
    ): string {
        $permissionId = DB::table('idn_permissions')->where('key', 'identity.assignments.manage')->value('id');
        if (! is_string($permissionId)) {
            $permissionId = (string) Str::uuid7();
            DB::table('idn_permissions')->insert([
                'id' => $permissionId,
                'key' => 'identity.assignments.manage',
                'layer' => 'ACTION',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('idn_permission_role')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'role_id' => $tenantAdminRoleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $approver->getKey(),
            'role_id' => $tenantAdminRoleId,
            'organization_id' => $organizationId,
            'facility_id' => null,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) DB::table('idn_admin_role_promotion_requests')
            ->where('tenant_id', $tenantId)
            ->where('role_key', 'tenant_admin')
            ->where('action', 'REVOKE')
            ->where('request_origin', 'HR_LIFECYCLE')
            ->where('hr_staff_id', $staffId)
            ->where('status', 'PENDING')
            ->value('id');
    });
    TenantContextScope::run($tenantId, $facilityId, function () use ($approver, $requestId): void {
        $this->actingAs($approver)
            ->post(route('admin.identity.role-promotions.approve', $requestId))
            ->assertRedirect(route('admin.identity.role-promotions'));
    });
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $linked, $tenantAdminRoleId, $organizationId, $approver): void {
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('role_id', $tenantAdminRoleId)
            ->where('status', 'REVOKED')->exists())->toBeTrue();
        expect(app(ScopeChecker::class)->effectiveOrganizationPermissions($linked, $organizationId))->toBe([]);
        expect(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
            ->where('actor_id', $approver->getKey())
            ->where('action', 'identity.admin_role_promotion.revocation_approved')
            ->where('target_id', DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
                ->where('user_id', $linked->getKey())->where('role_id', $tenantAdminRoleId)->value('id'))
            ->exists())->toBeTrue();
    });
});

it('rejects a privileged revocation approval after the linked staff member is reinstated', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $organizationId = (string) $fixture['organization']->id;
    $facilityId = (string) $fixture['facility']->id;
    tmrSelectFacility($this, $facilityId);
    $tenantAdminRoleId = tmrEnsureTenantAdminRole($tenantId);

    $personId = createPersonHelper($tenantId, 'Stale', 'K', 'Request', facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        app(CommandBus::class)->dispatch(new OnboardStaff($staffId, '2026-01-05'));
    });

    $linked = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
        'password' => Hash::make('correct-horse'),
    ]);
    $approver = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery'])),
        'two_factor_confirmed_at' => now(),
    ]);
    TenantContextScope::run($tenantId, null, function () use (
        $tenantId, $organizationId, $linked, $approver, $tenantAdminRoleId
    ): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $linked->getKey(),
            'role_id' => $tenantAdminRoleId,
            'organization_id' => $organizationId,
            'facility_id' => null,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('idn_permissions')->where('key', 'identity.assignments.manage')->value('id');
        if (! is_string($permissionId)) {
            $permissionId = (string) Str::uuid7();
            DB::table('idn_permissions')->insert([
                'id' => $permissionId,
                'key' => 'identity.assignments.manage',
                'layer' => 'ACTION',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('idn_permission_role')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'role_id' => $tenantAdminRoleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $approver->getKey(),
            'role_id' => $tenantAdminRoleId,
            'organization_id' => $organizationId,
            'facility_id' => null,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
    linkStaffUserHelper($tenantId, $staffId, (string) $linked->getKey(), $facilityId);

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.suspend', $staffId), ['reason' => 'Temporary review.'])
        ->assertRedirect();
    $requestId = TenantContextScope::run($tenantId, null, fn (): string => (string) DB::table('idn_admin_role_promotion_requests')
        ->where('tenant_id', $tenantId)
        ->where('target_user_id', $linked->getKey())
        ->where('role_key', 'tenant_admin')
        ->where('action', 'REVOKE')
        ->where('request_origin', 'HR_LIFECYCLE')
        ->where('status', 'PENDING')
        ->value('id'));
    expect($requestId)->not->toBe('');

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.reinstate', $staffId))
        ->assertRedirect();

    TenantContextScope::run($tenantId, $facilityId, function () use ($approver, $requestId): void {
        $this->actingAs($approver)
            ->post(route('admin.identity.role-promotions.approve', $requestId))
            ->assertStatus(409);
    });

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $linked, $staffId, $requestId, $tenantAdminRoleId): void {
        expect(DB::table('hr_staff')->where('tenant_id', $tenantId)->where('id', $staffId)->value('status'))->toBe('ACTIVE')
            ->and(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
                ->where('user_id', $linked->getKey())->where('status', 'ACTIVE')->exists())->toBeTrue()
            ->and(DB::table('idn_admin_role_promotion_requests')->where('tenant_id', $tenantId)
                ->where('id', $requestId)->value('status'))->toBe('CANCELLED')
            ->and(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
                ->where('action', 'identity.admin_role_promotion.revocation_cancelled')
                ->where('target_id', DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
                    ->where('user_id', $linked->getKey())->where('role_id', $tenantAdminRoleId)->value('id'))
                ->exists())->toBeTrue();
    });
});

it('suspends linked access tenant-wide and restores only suspended assignments', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $organizationId = (string) $fixture['organization']->id;
    $facilityA = (string) $fixture['facility']->id;
    tmrSelectFacility($this, $facilityA);

    $facilityB = TenantContextScope::run($tenantId, null, function () use ($tenantId, $fixture): string {
        /** @var Facility $facility */
        $facility = Facility::factory()->forTenant($tenantId, $fixture['organization']->id)->create([
            'name' => 'Suspension Second Clinic',
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'user_id' => $fixture['user']->getKey(), 'role_id' => $fixture['role_id'],
            'facility_id' => $facility->id, 'status' => 'ACTIVE',
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (string) $facility->id;
    });

    $nurseRoleId = tmrEnsureNurseRole($tenantId);
    $receptionistRoleId = tmrEnsureReceptionistRole($tenantId);
    $tenantAdminRoleId = tmrEnsureTenantAdminRole($tenantId);
    $facilityAdminRoleId = tmrEnsureFacilityAdminRole($tenantId);
    $preSuspendedId = (string) Str::uuid7();
    $addedAfterSuspensionId = (string) Str::uuid7();
    $revokedDuringSuspensionId = (string) Str::uuid7();

    $personId = createPersonHelper($tenantId, 'Sara', 'K', 'Suspend', facilityId: $facilityA);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityA);
    assignStaffHelper($tenantId, $staffId, $facilityA);
    assignStaffHelper($tenantId, $staffId, $facilityB);
    TenantContextScope::run($tenantId, $facilityA, function () use ($staffId): void {
        app(CommandBus::class)->dispatch(new OnboardStaff($staffId, '2026-01-05'));
    });
    $linked = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);
    $linked->forceFill(['password' => Hash::make('correct-horse')])->save();
    TenantContextScope::run($tenantId, null, function () use (
        $tenantId, $organizationId, $facilityA, $facilityB, $linked, $nurseRoleId, $receptionistRoleId,
        $tenantAdminRoleId, $facilityAdminRoleId, $preSuspendedId, $revokedDuringSuspensionId
    ): void {
        foreach ([$facilityA, $facilityB] as $index => $facilityId) {
            DB::table('idn_access_assignments')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
                'role_id' => $nurseRoleId, 'facility_id' => $facilityId, 'status' => 'ACTIVE',
                'organization_id' => $organizationId,
                'is_primary' => $index === 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('idn_access_assignments')->insert([
            'id' => $revokedDuringSuspensionId, 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $nurseRoleId, 'facility_id' => $facilityA, 'status' => 'ACTIVE',
            'organization_id' => $organizationId,
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $tenantAdminRoleId, 'organization_id' => $organizationId, 'facility_id' => null,
            'status' => 'ACTIVE', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $facilityAdminRoleId, 'organization_id' => $organizationId, 'facility_id' => $facilityA,
            'status' => 'ACTIVE', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Already-revoked assignment must never come back.
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $receptionistRoleId, 'facility_id' => $facilityA, 'status' => 'REVOKED',
            'organization_id' => $organizationId,
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => $preSuspendedId, 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $receptionistRoleId, 'facility_id' => $facilityB, 'status' => 'SUSPENDED',
            'organization_id' => $organizationId,
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('aut_sessions')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'session_id' => 'sess-susp-1', 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
            'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    linkStaffUserHelper($tenantId, $staffId, (string) $linked->getKey(), $facilityA);

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.suspend', $staffId), ['reason' => 'Misconduct review pending.'])
        ->assertRedirect();

    $this->actingAs($linked)
        ->get('/dashboard')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->from('/login')
        ->post('/login', ['email' => $linked->email, 'password' => 'correct-horse'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    TenantContextScope::run($tenantId, null, function () use (
        $tenantId, $organizationId, $facilityA, $facilityB, $linked, $staffId, $receptionistRoleId,
        $tenantAdminRoleId, $facilityAdminRoleId, $preSuspendedId
    ): void {
        expect(DB::table('hr_staff')->where('id', $staffId)->value('status'))->toBe('SUSPENDED');

        // Ordinary assignments are suspended; privileged assignments remain unchanged for review.
        $suspended = DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)->where('user_id', $linked->getKey())
            ->where('status', 'SUSPENDED')->count();
        expect($suspended)->toBe(4);
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('role_id', $tenantAdminRoleId)->value('status'))->toBe('ACTIVE');
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('role_id', $facilityAdminRoleId)->value('status'))->toBe('ACTIVE');
        expect(DB::table('idn_staff_access_suspension_assignments')->where('tenant_id', $tenantId)->count())->toBe(3);
        expect(DB::table('idn_staff_access_suspension_assignments')->where('tenant_id', $tenantId)
            ->where('assignment_id', $preSuspendedId)->exists())->toBeFalse();
        expect(DB::table('idn_admin_role_promotion_requests')->where('tenant_id', $tenantId)
            ->where('target_user_id', $linked->getKey())->where('action', 'REVOKE')
            ->where('request_origin', 'HR_LIFECYCLE')->where('status', 'PENDING')->count())->toBe(2);
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('role_id', $receptionistRoleId)
            ->where('facility_id', $facilityA)->value('status'))->toBe('REVOKED');

        // The blocked account cannot use these still-active assignments.
        expect(app(ScopeChecker::class)->effectivePermissions($linked, $facilityA))->not->toContain('patient.patients.read');
        expect(app(ScopeChecker::class)->effectivePermissions($linked, $facilityB))->not->toContain('patient.patients.read');
        expect(app(ScopeChecker::class)->effectiveOrganizationPermissions($linked, $organizationId))->toContain('admin.workspace.access');
        expect(app(LinkedStaffGrantEligibility::class)
            ->isBlockedByHrLifecycle((string) $linked->tenant_id, (string) $linked->getKey()))->toBeTrue();
        expect(DB::table('aut_sessions')->where('tenant_id', $tenantId)->where('user_id', $linked->getKey())
            ->whereNull('revoked_at')->exists())->toBeFalse();
        expect(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
            ->where('action', 'identity.access.suspended')->where('target_id', $linked->getKey())->count())->toBeGreaterThanOrEqual(2);
    });

    TenantContextScope::run($tenantId, null, function () use (
        $tenantId, $organizationId, $facilityB, $linked, $nurseRoleId, $receptionistRoleId,
        $addedAfterSuspensionId, $revokedDuringSuspensionId
    ): void {
        // Simulate an independent versioned assignment change and a later grant.
        DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())
            ->where('role_id', $nurseRoleId)
            ->where('facility_id', $facilityB)
            ->update(['version' => DB::raw('version + 1'), 'updated_at' => now()]);
        DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->where('id', $revokedDuringSuspensionId)
            ->update(['status' => 'REVOKED', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        DB::table('idn_access_assignments')->insert([
            'id' => $addedAfterSuspensionId, 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $receptionistRoleId, 'facility_id' => $facilityB, 'status' => 'ACTIVE',
            'organization_id' => $organizationId,
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $this->actingAs($fixture['user']);
    tmrSelectFacility($this, $facilityA);
    $this->post(route('hr.staff.reinstate', $staffId))
        ->assertRedirect();
    $this->actingAs($fixture['user']);
    tmrSelectFacility($this, $facilityA);
    $this->post(route('hr.staff.reinstate', $staffId))
        ->assertSessionHasErrors('lifecycle');

    TenantContextScope::run($tenantId, null, function () use (
        $tenantId, $organizationId, $facilityA, $facilityB, $linked, $staffId, $nurseRoleId,
        $receptionistRoleId, $tenantAdminRoleId, $facilityAdminRoleId, $preSuspendedId,
        $addedAfterSuspensionId, $revokedDuringSuspensionId
    ): void {
        expect(DB::table('hr_staff')->where('id', $staffId)->value('status'))->toBe('ACTIVE');
        // Only unchanged assignments from this operation return; other states and later grants survive.
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('role_id', $nurseRoleId)->where('status', 'ACTIVE')->count())->toBe(1);
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('role_id', $nurseRoleId)
            ->where('facility_id', $facilityB)->value('status'))->toBe('SUSPENDED');
        expect(DB::table('idn_access_assignments')->where('id', $preSuspendedId)->value('status'))->toBe('SUSPENDED');
        expect(DB::table('idn_access_assignments')->where('id', $addedAfterSuspensionId)->value('status'))->toBe('ACTIVE');
        expect(DB::table('idn_access_assignments')->where('id', $revokedDuringSuspensionId)->value('status'))->toBe('REVOKED');
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('role_id', $tenantAdminRoleId)->value('status'))->toBe('ACTIVE');
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('role_id', $facilityAdminRoleId)->value('status'))->toBe('ACTIVE');
        expect(DB::table('idn_admin_role_promotion_requests')->where('tenant_id', $tenantId)
            ->where('target_user_id', $linked->getKey())->where('action', 'REVOKE')
            ->where('request_origin', 'HR_LIFECYCLE')->where('status', 'CANCELLED')->count())->toBe(2);
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('role_id', $receptionistRoleId)
            ->where('facility_id', $facilityA)->value('status'))->toBe('REVOKED');
        expect(app(ScopeChecker::class)->effectivePermissions($linked, $facilityA))->toContain('patient.patients.read');
        expect(app(ScopeChecker::class)->effectivePermissions($linked, $facilityB))->not->toContain('patient.patients.read');
        expect(app(ScopeChecker::class)->effectiveOrganizationPermissions($linked, $organizationId))->toContain('admin.workspace.access');
        expect(DB::table('shared_audit_log')->where('tenant_id', $tenantId)
            ->where('action', 'identity.access.restored')->where('target_id', $linked->getKey())->exists())->toBeTrue();
    });
});

it('blocks HR actors from suspending or terminating their own linked staff records', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    tmrSelectFacility($this, $facilityId);

    $personId = createPersonHelper($tenantId, 'Self', 'K', 'Action', facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        app(CommandBus::class)->dispatch(new OnboardStaff($staffId, '2026-01-05'));
    });
    linkStaffUserHelper($tenantId, $staffId, (string) $fixture['user']->getKey(), $facilityId);

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.suspend', $staffId), ['reason' => 'Self-suspension attempt.'])
        ->assertForbidden();
    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.terminate', $staffId), [])
        ->assertForbidden();

    TenantContextScope::run($tenantId, null, function () use ($staffId): void {
        expect(DB::table('hr_staff')->where('id', $staffId)->value('status'))->toBe('ACTIVE');
    });
});

it('suspends staff without a linked user without touching identity', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    tmrSelectFacility($this, $facilityId);

    $personId = createPersonHelper($tenantId, 'No', 'K', 'LinkSusp', facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        app(CommandBus::class)->dispatch(new OnboardStaff($staffId, '2026-01-05'));
    });

    $assignmentsBefore = TenantContextScope::run($tenantId, null, fn (): int => DB::table('idn_access_assignments')
        ->where('tenant_id', $tenantId)->count());

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.suspend', $staffId), ['reason' => 'Precautionary suspension review.'])
        ->assertRedirect();

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $staffId, $assignmentsBefore): void {
        expect(DB::table('hr_staff')->where('id', $staffId)->value('status'))->toBe('SUSPENDED');
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)->count())->toBe($assignmentsBefore);
    });
});

it('leaves HR and identity unchanged when suspension preconditions fail', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    tmrSelectFacility($this, $facilityId);
    $nurseRoleId = tmrEnsureNurseRole($tenantId);

    // PROSPECT staff cannot transition to SUSPENDED.
    $personId = createPersonHelper($tenantId, 'Prospect', 'K', 'Staff', facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);
    $linked = User::factory()->forTenant($tenantId)->create(['email_verified_at' => now(), 'status' => 'ACTIVE']);
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $linked, $nurseRoleId): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $linked->getKey(),
            'role_id' => $nurseRoleId, 'facility_id' => $facilityId, 'status' => 'ACTIVE',
            'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    linkStaffUserHelper($tenantId, $staffId, (string) $linked->getKey(), $facilityId);

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.suspend', $staffId), ['reason' => 'Invalid transition attempt.'])
        ->assertSessionHasErrors('lifecycle');

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $linked, $staffId): void {
        expect(DB::table('hr_staff')->where('id', $staffId)->value('status'))->toBe('PROSPECT');
        expect(DB::table('idn_access_assignments')->where('tenant_id', $tenantId)
            ->where('user_id', $linked->getKey())->where('facility_id', $facilityId)->value('status'))->toBe('ACTIVE');
    });
});
