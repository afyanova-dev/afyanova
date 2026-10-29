<?php

declare(strict_types=1);

use App\Domains\HR\Domain\ValueObject\AssignmentScope;
use App\Domains\HR\Domain\ValueObject\CadreCategory;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/hr.php';

it('shows one onboarding row per staff member with the next action', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $cadreId = defineCadreHelper($tenantId, 'ONB-NURSE', 'Onboarding Nurse', CadreCategory::CLINICAL);
    $positionId = definePositionHelper($tenantId, $cadreId, 'ONB-RN', 'Onboarding Registered Nurse');
    $personId = createPersonHelper(
        tenantId: $tenantId,
        firstName: 'Neema',
        middleName: null,
        lastName: 'Mushi',
        email: 'neema.mushi@example.test',
        facilityId: $facilityId,
    );
    $staffId = createStaffHelper($tenantId, $personId, $cadreId, $positionId, $facilityId);
    assignStaffHelper(
        tenantId: $tenantId,
        staffId: $staffId,
        facilityId: $facilityId,
        scope: AssignmentScope::FACILITY,
        effectiveFrom: '2026-01-01',
    );

    $this->get(route('hr.staff.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Onboarding')
            ->where('focus', 'needs-action')
            ->where('rows.0.id', $staffId)
            ->where('rows.0.name', 'Neema Mushi')
            ->where('rows.0.staffStatus', 'PROSPECT')
            ->where('rows.0.placement.state', 'CONFIRMED')
            ->where('rows.0.accountState', 'NO_ACCOUNT')
            ->where('rows.0.nextAction.kind', 'INVITE_ACCOUNT')
            ->where('rows.0.nextAction.label', 'Invite account')
            ->where('rows.0.needsAction', true)
            ->where('summary.readyToOnboard', 1)
            ->where('summary.needsAction', 1)
            ->where('permissions.canReviewPlacements', true)
            ->where('permissions.canManageAccess', true));
});

it('accepts a dashboard focus filter for the onboarding queue', function (): void {
    $fixture = hrFixture();

    $this->get(route('hr.staff.onboarding', ['focus' => 'placements']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Onboarding')
            ->where('focus', 'placements'));

    $this->get(route('hr.staff.onboarding', ['focus' => 'ready']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Onboarding')
            ->where('focus', 'ready'));

    $this->get(route('hr.staff.onboarding', ['focus' => 'not-a-view']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Onboarding')
            ->where('focus', 'needs-action'));
});

it('lets staff readers inspect the queue without action permissions', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $cadreId = defineCadreHelper($tenantId, 'READ-NURSE', 'Read-only Nurse', CadreCategory::CLINICAL);
    $positionId = definePositionHelper($tenantId, $cadreId, 'READ-RN', 'Read-only Registered Nurse');
    $personId = createPersonHelper(
        tenantId: $tenantId,
        firstName: 'Rehema',
        middleName: null,
        lastName: 'Mkono',
        email: 'rehema.mkono@example.test',
        facilityId: $facilityId,
    );
    $staffId = createStaffHelper($tenantId, $personId, $cadreId, $positionId, $facilityId);
    assignStaffHelper(
        tenantId: $tenantId,
        staffId: $staffId,
        facilityId: $facilityId,
        scope: AssignmentScope::FACILITY,
        effectiveFrom: '2026-01-01',
    );

    TenantContextScope::run($tenantId, $facilityId, function () use ($fixture, $tenantId): void {
        DB::table('idn_permission_role')->where('role_id', $fixture['role_id'])->delete();
        foreach (['hr.access', 'hr.staff.read'] as $permissionKey) {
            $permissionId = DB::table('idn_permissions')->where('key', $permissionKey)->value('id');
            expect($permissionId)->toBeString();
            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'role_id' => $fixture['role_id'],
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    $this->get(route('hr.staff.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Onboarding')
            ->where('rows.0.id', $staffId)
            ->where('rows.0.nextAction.kind', 'VIEW_PROFILE')
            ->where('rows.0.needsAction', false)
            ->where('summary.needsAction', 0)
            ->where('permissions.canReviewPlacements', false)
            ->where('permissions.canManageStaff', false)
            ->where('permissions.canManageAccess', false));
});
