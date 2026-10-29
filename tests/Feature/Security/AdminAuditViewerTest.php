<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Models\Tenant;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function insertAdminAuditEvent(
    string $tenantId,
    ?string $facilityId,
    string $action,
    array $metadata = [],
    ?string $reason = null,
): string {
    $id = (string) Str::uuid7();
    DB::table('shared_audit_log')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'actor_id' => (string) Str::uuid7(),
        'action' => $action,
        'target_type' => 'facility',
        'target_id' => $facilityId,
        'reason' => $reason,
        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        'created_at' => now()->subDays(2),
    ]);

    return $id;
}

function confirmAdminTwoFactor(object $user): void
{
    $user->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();
}

it('shows only tenant configuration and security events with allowlisted metadata', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'admin.workspace.access',
        'audit.events.read',
    ]);
    confirmAdminTwoFactor($user);

    TenantContextScope::run($tenant->id, $facility->id, function () use ($tenant, $facility): void {
        insertAdminAuditEvent($tenant->id, $facility->id, 'organization.capability.updated', [
            'code' => 'WOUND_CARE',
            'status' => 'ENABLED',
            'secret' => 'must-not-appear',
            'email' => 'private@example.test',
        ], 'A raw operator reason must not be shown.');
        insertAdminAuditEvent($tenant->id, $facility->id, 'auth.credential.mfa_recovery_used');
        insertAdminAuditEvent($tenant->id, $facility->id, 'procedures.protocol_approval_granted');
        insertAdminAuditEvent($tenant->id, $facility->id, 'procedures.completed');
        insertAdminAuditEvent($tenant->id, $facility->id, 'patient.patient_updated');
    });
    $otherTenant = Tenant::factory()->create();
    TenantContextScope::run($otherTenant->id, null, function () use ($otherTenant): void {
        insertAdminAuditEvent($otherTenant->id, null, 'identity.assignment.grant');
    });

    TenantContextScope::run($tenant->id, $facility->id, function () use ($tenant, $facility, $user): void {
        $this->actingAs($user)
            ->get(route('admin.workspace.audit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Audit/Index')
                ->where('total', 3)
                ->has('entries', 3)
                ->where('entries.0.action', 'procedures.protocol_approval_granted')
                ->where('entries.1.action', 'auth.credential.mfa_recovery_used')
                ->where('entries.2.action', 'organization.capability.updated')
                ->where('entries.2.metadata', ['code' => 'WOUND_CARE', 'status' => 'ENABLED'])
                ->missing('entries.2.reason')
                ->missing('entries.2.metadata.secret')
                ->missing('entries.2.metadata.email'));

        $this->actingAs($user)
            ->get(route('admin.workspace.audit', ['action' => 'auth.credential']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('total', 1)
                ->where('entries.0.action', 'auth.credential.mfa_recovery_used'));

        insertAdminAuditEvent($tenant->id, $facility->id, 'queue.configuration.rooms_updated', [
            'room_count' => 2,
        ]);
        $this->actingAs($user)
            ->get(route('admin.workspace.audit', ['action' => 'queue.configuration.rooms_updated']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('total', 1)
                ->where('entries.0.metadata', ['room_count' => 2]));
    });
});

it('requires the dedicated audit-read permission', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'admin.workspace.access',
    ]);
    confirmAdminTwoFactor($user);

    TenantContextScope::run($tenant->id, $facility->id, function () use ($user): void {
        $this->actingAs($user)->get(route('admin.workspace.audit'))->assertForbidden();
    });
});

it('requires confirmed two-factor authentication for audit access', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'admin.workspace.access',
        'audit.events.read',
    ]);

    TenantContextScope::run($tenant->id, $facility->id, function () use ($user): void {
        $this->actingAs($user)
            ->get(route('admin.workspace.audit'))
            ->assertRedirect(route('admin.security.two-factor.setup'));
    });
});

it('limits facility-scoped readers to the active facility and rejects unauthorized facility filters', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'admin.workspace.access',
        'audit.events.read',
    ]);
    confirmAdminTwoFactor($user);

    $otherFacility = TenantContextScope::run($tenant->id, null, function () use ($tenant, $organization, $facility): Facility {
        $otherFacility = Facility::factory()->forTenant($tenant->id, $organization->id)->create();
        insertAdminAuditEvent($tenant->id, $otherFacility->id, 'organization.capability.updated');
        insertAdminAuditEvent($tenant->id, $facility->id, 'organization.capability.updated');

        return $otherFacility;
    });

    TenantContextScope::run($tenant->id, $facility->id, function () use ($user, $facility, $otherFacility): void {
        $this->actingAs($user)
            ->get(route('admin.workspace.audit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('total', 1)
                ->where('entries.0.facilityId', $facility->id));

        $this->actingAs($user)
            ->get(route('admin.workspace.audit', ['facility_id' => $otherFacility->id]))
            ->assertForbidden();
    });
});

it('allows an organization-scoped administrator to view tenant and organization-level events', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = makeTenantWithUser([
        'admin.workspace.access',
        'audit.events.read',
    ]);
    confirmAdminTwoFactor($user);

    TenantContextScope::run($tenant->id, null, function () use ($tenant, $organization, $user, $roleId): void {
        DB::table('idn_roles')->where('id', $roleId)->update(['scope_type' => 'ORG']);
        DB::table('idn_access_assignments')
            ->where('user_id', $user->getKey())
            ->update(['facility_id' => null, 'organization_id' => $organization->id]);
        insertAdminAuditEvent($tenant->id, null, 'identity.assignment.grant');
    });
    TenantContextScope::run($tenant->id, $facility->id, function () use ($tenant, $facility): void {
        insertAdminAuditEvent($tenant->id, $facility->id, 'organization.capability.updated');
    });

    TenantContextScope::run($tenant->id, $facility->id, function () use ($user): void {
        $this->actingAs($user)
            ->get(route('admin.workspace.audit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('total', 2)
                ->has('entries', 2));
    });
});

it('bounds audit date windows and page sizes', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'admin.workspace.access',
        'audit.events.read',
    ]);
    confirmAdminTwoFactor($user);

    TenantContextScope::run($tenant->id, $facility->id, function () use ($user): void {
        $this->actingAs($user)
            ->get(route('admin.workspace.audit', [
                'from' => now()->subDays(100)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertSessionHasErrors('from');

        $this->actingAs($user)
            ->get(route('admin.workspace.audit', ['per_page' => 101]))
            ->assertSessionHasErrors('per_page');
    });
});
