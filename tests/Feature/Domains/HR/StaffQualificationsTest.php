<?php

declare(strict_types=1);

use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/hr.php';

it('records and updates staff qualifications independently of position and registration', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $personId = createPersonHelper($tenantId, 'Neema', null, 'Mushi');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.qualifications.store', ['staff' => $staffId]), [
            'award' => 'Diploma',
            'field_of_study' => 'Clinical Medicine',
            'institution' => 'Tanzania Training College',
            'completion_year' => 2020,
            'verification_status' => 'VERIFIED',
        ])
        ->assertRedirect();

    $qualification = TenantContextScope::run($tenantId, $facilityId, fn (): object => DB::table('hr_qualifications')
        ->where('tenant_id', $tenantId)
        ->where('staff_id', $staffId)
        ->firstOrFail());

    expect($qualification->award)->toBe('Diploma')
        ->and($qualification->completion_year)->toBe(2020)
        ->and($qualification->verification_status)->toBe('VERIFIED')
        ->and($qualification->verified_by)->toBe((string) $fixture['user']->getKey())
        ->and($qualification->verified_at)->not->toBeNull();

    $this->actingAs($fixture['user'])
        ->get(route('hr.staff.show', ['staff' => $staffId]).'?section=qualifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Show')
            ->where('qualifications.0.award', 'Diploma')
            ->where('qualifications.0.fieldOfStudy', 'Clinical Medicine')
            ->where('qualifications.0.verificationStatus', 'VERIFIED'));

    $this->actingAs($fixture['user'])
        ->put(route('hr.staff.qualifications.update', [
            'staff' => $staffId,
            'qualification' => $qualification->id,
        ]), [
            'award' => 'Advanced Diploma',
            'field_of_study' => 'Clinical Medicine',
            'institution' => 'Tanzania Training College',
            'completion_year' => 2021,
            'verification_status' => 'UNVERIFIED',
        ])
        ->assertRedirect();

    $updated = TenantContextScope::run($tenantId, $facilityId, fn (): object => DB::table('hr_qualifications')
        ->where('id', $qualification->id)
        ->firstOrFail());

    expect($updated->award)->toBe('Advanced Diploma')
        ->and($updated->completion_year)->toBe(2021)
        ->and($updated->verification_status)->toBe('UNVERIFIED')
        ->and($updated->verified_by)->toBeNull()
        ->and($updated->verified_at)->toBeNull()
        ->and($updated->version)->toBe(1);
});

it('requires staff management permission to manage qualifications', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $personId = createPersonHelper($tenantId, 'Asha', null, 'John');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);

    TenantContextScope::run($tenantId, $facilityId, function () use ($fixture, $tenantId): void {
        $permissionId = DB::table('idn_permissions')->where('key', 'hr.staff.manage')->value('id');
        DB::table('idn_permission_role')
            ->where('tenant_id', $tenantId)
            ->where('role_id', $fixture['role_id'])
            ->where('permission_id', $permissionId)
            ->delete();
    });

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.qualifications.store', ['staff' => $staffId]), [
            'award' => 'Certificate',
            'field_of_study' => 'Nursing',
            'institution' => 'Training College',
            'verification_status' => 'UNVERIFIED',
        ])
        ->assertForbidden();

    expect(TenantContextScope::run($tenantId, $facilityId, fn (): bool => DB::table('hr_qualifications')
        ->where('tenant_id', $tenantId)
        ->where('staff_id', $staffId)
        ->exists()))->toBeFalse();
});

it('does not disclose qualifications to facility assignment reviewers without staff access', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $personId = createPersonHelper($tenantId, 'Rehema', null, 'Kassim');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);

    TenantContextScope::run($tenantId, $facilityId, function () use ($fixture, $tenantId, $staffId): void {
        DB::table('hr_qualifications')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'award' => 'Diploma',
            'field_of_study' => 'Nursing',
            'institution' => 'Training College',
            'verification_status' => 'UNVERIFIED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['hr.staff.read', 'hr.staff.manage'] as $key) {
            $permissionId = DB::table('idn_permissions')->where('key', $key)->value('id');
            DB::table('idn_permission_role')
                ->where('tenant_id', $tenantId)
                ->where('role_id', $fixture['role_id'])
                ->where('permission_id', $permissionId)
                ->delete();
        }

        foreach (['admin.workspace.access', 'identity.assignments.manage'] as $key) {
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
                'role_id' => $fixture['role_id'],
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    $this->actingAs($fixture['user'])
        ->get(route('hr.staff.show', ['staff' => $staffId]).'?section=qualifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canReadQualifications', false)
            ->where('qualifications', []));
});
