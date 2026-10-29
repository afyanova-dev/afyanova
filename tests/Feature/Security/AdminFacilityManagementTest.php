<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function seedAdminFacilityReferenceData(): void
{
    $references = [
        'org_facility_types' => ['DISPENSARY' => ['name' => 'Dispensary', 'active' => true]],
        'org_care_levels' => ['PRIMARY' => ['name' => 'Community / Primary', 'rank' => 1, 'active' => true]],
        'org_ownership_types' => ['PUBLIC' => ['name' => 'Public']],
    ];

    foreach ($references as $table => $rows) {
        foreach ($rows as $code => $attributes) {
            if (! DB::table($table)->where('code', $code)->exists()) {
                DB::table($table)->insert([
                    'id' => (string) Str::uuid7(),
                    'code' => $code,
                    ...$attributes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}

/**
 * @param  list<string>  $permissions
 * @return array{
 *     tenant: Tenant,
 *     organization: Organization,
 *     facility: Facility,
 *     user: User,
 *     role_id: string
 * }
 */
function makeOrganizationScopedFacilityAdmin(array $permissions): array
{
    $fixture = makeTenantWithUser($permissions);
    $fixture['user']->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture): void {
        DB::table('idn_roles')
            ->where('id', $fixture['role_id'])
            ->update(['scope_type' => 'ORG']);
        DB::table('idn_access_assignments')
            ->where('user_id', $fixture['user']->getKey())
            ->update([
                'organization_id' => $fixture['organization']->id,
                'facility_id' => null,
            ]);
    });

    return $fixture;
}

it('creates an active facility for an organization administrator and audits it atomically', function (): void {
    seedAdminFacilityReferenceData();
    $fixture = makeOrganizationScopedFacilityAdmin([
        'admin.workspace.access',
        'organization.facilities.read',
        'organization.facilities.manage',
    ]);
    $payload = [
        'name' => 'New Community Clinic',
        'code' => 'NEW_CLINIC_01',
        'facility_type' => 'DISPENSARY',
        'care_level' => 'PRIMARY',
        'ownership_type' => 'PUBLIC',
    ];

    $response = $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $fixture['facility']->id])
        ->post(route('admin.workspace.organization.facilities.store'), $payload);
    $response->assertRedirect(route('admin.workspace.organization'));

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $payload): void {
        $facility = DB::table('org_facilities')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('code', $payload['code'])
            ->first();
        if ($facility === null) {
            throw new RuntimeException('Expected the facility creation request to persist its facility.');
        }

        expect($facility->organization_id)->toBe($fixture['organization']->id)
            ->and($facility->status)->toBe('ACTIVE');
        expect(DB::table('org_facility_capabilities')->where('facility_id', $facility->id)->count())->toBe(0);

        $audit = DB::table('shared_audit_log')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('action', 'organization.facility.created')
            ->where('target_id', $facility->id)
            ->first();
        if ($audit === null) {
            throw new RuntimeException('Expected the facility creation audit event to be persisted.');
        }

        expect($audit->actor_id)->toBe((string) $fixture['user']->getKey())
            ->and($audit->facility_id)->toBe($facility->id);
    });
});

it('does not let a facility-scoped manager create a facility elsewhere in the organization', function (): void {
    seedAdminFacilityReferenceData();
    $fixture = makeTenantWithUser([
        'admin.workspace.access',
        'organization.facilities.read',
        'organization.facilities.manage',
    ]);
    $fixture['user']->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $fixture['facility']->id])
        ->post(route('admin.workspace.organization.facilities.store'), [
            'name' => 'Out-of-scope Facility',
            'code' => 'OUT_OF_SCOPE',
            'facility_type' => 'DISPENSARY',
            'care_level' => 'PRIMARY',
            'ownership_type' => 'PUBLIC',
        ])
        ->assertForbidden();

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        expect(DB::table('org_facilities')->where('tenant_id', $fixture['tenant']->id)->count())->toBe(1);
    });
});

it('rejects inactive reference data without creating or auditing a facility', function (): void {
    seedAdminFacilityReferenceData();
    DB::table('org_facility_types')->where('code', 'DISPENSARY')->update(['active' => false]);
    $fixture = makeOrganizationScopedFacilityAdmin([
        'admin.workspace.access',
        'organization.facilities.read',
        'organization.facilities.manage',
    ]);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $fixture['facility']->id])
        ->post(route('admin.workspace.organization.facilities.store'), [
            'name' => 'Invalid Reference Facility',
            'code' => 'INVALID_REFERENCE',
            'facility_type' => 'DISPENSARY',
            'care_level' => 'PRIMARY',
            'ownership_type' => 'PUBLIC',
        ])
        ->assertSessionHasErrors('facility_type');

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        expect(DB::table('org_facilities')->where('tenant_id', $fixture['tenant']->id)->count())->toBe(1)
            ->and(DB::table('shared_audit_log')
                ->where('tenant_id', $fixture['tenant']->id)
                ->where('action', 'organization.facility.created')
                ->count())->toBe(0);
    });
});
