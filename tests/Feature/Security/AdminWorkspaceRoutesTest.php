<?php

declare(strict_types=1);

use App\Domains\Identity\Application\TenantAdminRoleProvisioner;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array{tenant: Tenant, facility: Facility, organization: Organization, user: User}
 */
function makeTenantAdminWithoutSelectedFacility(): array
{
    $fixture = makeTenantWithUser();
    $user = $fixture['user'];

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture, $user): void {
        DB::table('idn_access_assignments')
            ->where('user_id', $user->getKey())
            ->update(['status' => 'REVOKED']);

        Facility::factory()->forTenant($fixture['tenant']->id, $fixture['organization']->id)->create();
        $roleId = app(TenantAdminRoleProvisioner::class)->provision(
            tenantId: $fixture['tenant']->id,
            actorId: null,
            reason: 'Provisioned in the Admin workspace test.',
        );
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $user->getKey(),
            'role_id' => $roleId,
            'organization_id' => $fixture['organization']->id,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return $fixture;
}

it('routes an authorized administrator without confirmed two-factor authentication to enrollment', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser(['admin.workspace.access']);

    TenantContextScope::run($tenant->id, $facility->id, function () use ($user): void {
        $this->actingAs($user)
            ->get(route('admin.workspace.index'))
            ->assertRedirect(route('admin.security.two-factor.setup'));
    });
});

it('denies the Admin workspace to users without its dedicated permission', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser();
    $user->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();

    TenantContextScope::run($tenant->id, $facility->id, function () use ($user): void {
        $this->actingAs($user)
            ->get(route('admin.workspace.index'))
            ->assertForbidden();
    });
});

it('lists only the Admin management areas allowed by the current permissions', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'admin.workspace.access',
        'organization.facilities.read',
        'identity.assignments.read',
        'audit.events.read',
    ]);
    $user->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();

    TenantContextScope::run($tenant->id, $facility->id, function () use ($user): void {
        $this->actingAs($user)
            ->get(route('admin.workspace.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Index')
                ->where('canManageOrganization', true)
                ->where('canReviewRoleRequests', true)
                ->where('canReviewAudit', true));
    });
});

it('lands a Tenant Admin with multiple facilities in Admin and guides them through required two-factor setup', function (): void {
    $fixture = makeTenantAdminWithoutSelectedFacility();

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture): void {
        $this->actingAs($fixture['user'])
            ->get(route('dashboard'))
            ->assertRedirect(route('admin.workspace.index'));

        $this->get(route('admin.workspace.index'))
            ->assertRedirect(route('admin.security.two-factor.setup'));

        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get(route('admin.security.two-factor.setup'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/TwoFactorSetup')
                ->where('continueTo', route('admin.workspace.index')));
    });
});

it('shows organization-scoped Admin tools without requiring a selected facility', function (): void {
    $fixture = makeTenantAdminWithoutSelectedFacility();
    $fixture['user']->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();

    TenantContextScope::run($fixture['tenant']->id, null, function () use ($fixture): void {
        $this->actingAs($fixture['user'])
            ->get(route('admin.workspace.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Index')
                ->where('canManageOrganization', true)
                ->where('canReviewRoleRequests', true)
                ->where('canReviewAudit', true)
                ->where('auth.can', fn (Collection $can): bool => $can->get('admin.workspace.access') === true));

        $this->get(route('admin.identity.role-promotions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Identity/RolePromotions')
                ->where('canInviteFacilityAdmins', true));

        $this->get(route('admin.workspace.organization'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Organization/Index'));

        $this->get(route('admin.workspace.audit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Audit/Index'));
    });
});

it('requires recent password confirmation before an administrator can enroll two-factor authentication', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser(['admin.workspace.access']);

    TenantContextScope::run($tenant->id, $facility->id, function () use ($user): void {
        $this->actingAs($user)
            ->get(route('admin.security.two-factor.setup'))
            ->assertRedirect(route('password.confirm'));
    });
});

it('renders the enrollment screen after recent password confirmation', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser(['admin.workspace.access']);

    TenantContextScope::run($tenant->id, $facility->id, function () use ($user): void {
        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get(route('admin.security.two-factor.setup'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/TwoFactorSetup')
                ->where('setupPending', false)
                ->where('continueTo', route('admin.workspace.index')));
    });
});
