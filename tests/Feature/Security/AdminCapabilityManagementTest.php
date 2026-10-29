<?php

declare(strict_types=1);

use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../tests/Support/security.php';

beforeEach(function (): void {
    // Global capability registry — no tenant_id, no RLS. Mirrors
    // GlobalReferenceDataSeeder entries used here (including the
    // INPATIENT_CARE → PHARMACY dependency).
    foreach ([
        ['OUTPATIENT_CARE', 'Outpatient Care', 'CARE'],
        ['PHARMACY', 'Pharmacy', 'MEDICATION'],
        ['INPATIENT_CARE', 'Inpatient Care', 'CARE'],
    ] as [$code, $name, $category]) {
        DB::table('org_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'code' => $code, 'name' => $name,
            'category' => $category, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('org_capability_dependencies')->insert([
        'id' => (string) Str::uuid7(), 'capability_code' => 'INPATIENT_CARE', 'depends_on_code' => 'PHARMACY',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

function makeCapabilityAdmin(array $permissions = []): array
{
    $fixture = makeTenantWithUser([
        'admin.workspace.access',
        'organization.facilities.read',
        ...$permissions,
    ]);
    $fixture['user']->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $fixture;
}

function capabilityState(string $tenantId, string $facilityId, string $code): ?string
{
    return TenantContextScope::run($tenantId, null, fn (): ?string => DB::table('org_facility_capabilities')
        ->where('tenant_id', $tenantId)
        ->where('facility_id', $facilityId)
        ->where('capability_code', $code)
        ->value('state'));
}

it('enables a capability and audits the actor and facility', function (): void {
    $fixture = makeCapabilityAdmin(['organization.capabilities.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('admin.workspace.organization.capabilities.status', ['capability' => 'OUTPATIENT_CARE']), [
            'facility_id' => $facilityId,
            'state' => 'ENABLED',
        ])
        ->assertRedirect(route('admin.workspace.organization', ['facility_id' => $facilityId]))
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Capability OUTPATIENT_CARE enabled'));

    expect(capabilityState($tenantId, $facilityId, 'OUTPATIENT_CARE'))->toBe('ENABLED');

    $audit = TenantContextScope::run($tenantId, null, fn () => DB::table('shared_audit_log')
        ->where('tenant_id', $tenantId)
        ->where('action', 'organization.capability.enabled')
        ->where('target_id', $facilityId)
        ->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $fixture['user']->getKey())
        ->and($audit->facility_id)->toBe($facilityId);
});

it('refuses to enable a capability with unmet dependencies until they are enabled', function (): void {
    $fixture = makeCapabilityAdmin(['organization.capabilities.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('admin.workspace.organization.capabilities.status', ['capability' => 'INPATIENT_CARE']), [
            'facility_id' => $facilityId,
            'state' => 'ENABLED',
        ])
        ->assertStatus(422);
    expect(capabilityState($tenantId, $facilityId, 'INPATIENT_CARE'))->toBeNull();

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('admin.workspace.organization.capabilities.status', ['capability' => 'PHARMACY']), [
            'facility_id' => $facilityId,
            'state' => 'ENABLED',
        ])
        ->assertRedirect();

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('admin.workspace.organization.capabilities.status', ['capability' => 'INPATIENT_CARE']), [
            'facility_id' => $facilityId,
            'state' => 'ENABLED',
        ])
        ->assertRedirect();
    expect(capabilityState($tenantId, $facilityId, 'INPATIENT_CARE'))->toBe('ENABLED');
});

it('requires a reason when suspending and records it', function (): void {
    $fixture = makeCapabilityAdmin(['organization.capabilities.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('admin.workspace.organization.capabilities.status', ['capability' => 'PHARMACY']), [
            'facility_id' => $facilityId,
            'state' => 'SUSPENDED',
            'reason' => '',
        ])
        ->assertStatus(422);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('admin.workspace.organization.capabilities.status', ['capability' => 'PHARMACY']), [
            'facility_id' => $facilityId,
            'state' => 'SUSPENDED',
            'reason' => 'Stock audit in progress.',
        ])
        ->assertRedirect(route('admin.workspace.organization', ['facility_id' => $facilityId]));

    expect(capabilityState($tenantId, $facilityId, 'PHARMACY'))->toBe('SUSPENDED');
    $record = TenantContextScope::run($tenantId, null, fn () => DB::table('org_facility_capabilities')
        ->where('tenant_id', $tenantId)
        ->where('facility_id', $facilityId)
        ->where('capability_code', 'PHARMACY')
        ->first(['suspended_reason']));
    expect($record->suspended_reason)->toBe('Stock audit in progress.');
    expect(TenantContextScope::run($tenantId, null, fn (): bool => DB::table('shared_audit_log')
        ->where('tenant_id', $tenantId)
        ->where('action', 'organization.capability.suspended')
        ->where('target_id', $facilityId)
        ->exists()))->toBeTrue();
});

it('disables an enabled capability', function (): void {
    $fixture = makeCapabilityAdmin(['organization.capabilities.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('admin.workspace.organization.capabilities.status', ['capability' => 'PHARMACY']), [
            'facility_id' => $facilityId,
            'state' => 'ENABLED',
        ])
        ->assertRedirect();

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('admin.workspace.organization.capabilities.status', ['capability' => 'PHARMACY']), [
            'facility_id' => $facilityId,
            'state' => 'DISABLED',
        ])
        ->assertRedirect(route('admin.workspace.organization', ['facility_id' => $facilityId]))
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Capability PHARMACY disabled'));

    expect(capabilityState($tenantId, $facilityId, 'PHARMACY'))->toBe('DISABLED');
});

it('rejects an unregistered capability code', function (): void {
    $fixture = makeCapabilityAdmin(['organization.capabilities.manage']);
    $facilityId = (string) $fixture['facility']->id;

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('admin.workspace.organization.capabilities.status', ['capability' => 'NOT_A_SERVICE']), [
            'facility_id' => $facilityId,
            'state' => 'ENABLED',
        ])
        ->assertStatus(422);
});

it('denies capability changes without the manage permission', function (): void {
    $fixture = makeCapabilityAdmin();
    $facilityId = (string) $fixture['facility']->id;

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('admin.workspace.organization.capabilities.status', ['capability' => 'PHARMACY']), [
            'facility_id' => $facilityId,
            'state' => 'ENABLED',
        ])
        ->assertForbidden();
});

it('denies a facility-scoped admin from changing another facility', function (): void {
    $otherFacilityId = (string) Str::uuid7();
    $fixture = makeTenantWithUser([
        'admin.workspace.access',
        'organization.facilities.read',
        'organization.capabilities.manage',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $fixture['user']->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $otherFacilityId, $fixture): void {
        DB::table('org_facilities')->insert([
            'id' => $otherFacilityId,
            'tenant_id' => $tenantId,
            'organization_id' => $fixture['organization']->id,
            'name' => 'Far Clinic',
            'code' => 'FAR-01',
            'facility_type' => 'DISPENSARY',
            'care_level' => 'PRIMARY',
            'ownership_type' => 'PUBLIC',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $fixture['user']->getKey())
            ->update(['facility_id' => $otherFacilityId]);
    });

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $otherFacilityId])
        ->post(route('admin.workspace.organization.capabilities.status', ['capability' => 'PHARMACY']), [
            'facility_id' => $facilityId,
            'state' => 'ENABLED',
        ])
        ->assertForbidden();
    expect(capabilityState($tenantId, $facilityId, 'PHARMACY'))->toBeNull();
});
