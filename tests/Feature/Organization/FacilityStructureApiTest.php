<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Department;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Unit;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function structureFixture(array $permissions = ['organization.structure.read']): array
{
    $fx = makeTenantWithUser($permissions, []);

    return ['tenant' => $fx['tenant'], 'organization' => $fx['organization'], 'facility' => $fx['facility'], 'user' => $fx['user']];
}

function seedStructure(string $tenantId, string $facilityId): void
{
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId): void {
        $dept = Department::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'name' => 'Outpatient',
            'code' => 'OPD',
        ]);
        Unit::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'department_id' => $dept->id,
            'name' => 'Consultation',
            'code' => 'CONSULT',
            'unit_type' => 'GENERIC',
        ]);
    });
}

it('returns the active facility structure as departments with nested units', function (): void {
    $fx = structureFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    seedStructure($tenantId, $facilityId);

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->getJson('/workspaces/organization/api/structure');

    $response->assertOk()
        ->assertJsonPath('facilityId', $facilityId)
        ->assertJsonPath('departments.0.code', 'OPD')
        ->assertJsonPath('departments.0.units.0.code', 'CONSULT');

    $units = $response->json('departments.0.units.0');
    expect(array_keys($units))->toEqualCanonicalizing(['id', 'name', 'code']);
});

it('forbids the structure endpoint without the structure read permission', function (): void {
    $fx = structureFixture([]);
    $facilityId = (string) $fx['facility']->id;

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->getJson('/workspaces/organization/api/structure')
        ->assertForbidden();
});

it('forbids the structure endpoint without an active facility', function (): void {
    $fx = structureFixture();
    $tenantId = (string) $fx['tenant']->id;
    $orgId = (string) $fx['organization']->id;

    $otherFacilityId = TenantContextScope::run($tenantId, null, function () use ($tenantId, $orgId): string {
        $facility = Facility::factory()->forTenant($tenantId, $orgId)->create();

        return (string) $facility->id;
    });

    // A second assignment leaves no single auto-selected facility, so the
    // context resolves facility-less without an explicit session choice.
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $fx, $otherFacilityId): void {
        $roleId = DB::table('idn_roles')->where('tenant_id', $tenantId)->value('id');
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $fx['user']->id,
            'role_id' => $roleId,
            'facility_id' => $otherFacilityId,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($fx['user'])
        ->getJson('/workspaces/organization/api/structure')
        ->assertForbidden();
});
