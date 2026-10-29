<?php

declare(strict_types=1);

use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Capability\FacilityCapabilities;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 2 gate — authorization mechanism
 | (IMPLEMENTATION-CONTRACTS.md §12; docs/00-foundation/03-identity-rbac.md
 | §27–§28, §55–§56).
 */

function establish(string $tenantId, ?string $facilityId = null): void
{
    app(TenantContext::class)->establish($tenantId, $facilityId);
}

it('grants a permission the user holds via an assigned role', function (): void {
    ['user' => $user, 'tenant' => $tenant, 'facility' => $facility] = makeTenantWithUser(['identity.users.read']);
    establish($tenant->id, $facility->id);

    Tenancy::run($tenant->id, function () use ($user): void {
        expect($user->can('identity.users.read'))->toBeTrue();
    });
});

it('denies a registered permission the user does NOT hold (default deny)', function (): void {
    ['user' => $user, 'tenant' => $tenant, 'facility' => $facility] = makeTenantWithUser(['identity.users.read']);
    establish($tenant->id, $facility->id);

    Tenancy::run($tenant->id, function () use ($user): void {
        expect($user->can('identity.roles.assign'))->toBeFalse();
    });
});

it('denies an unregistered permission key', function (): void {
    ['user' => $user, 'tenant' => $tenant, 'facility' => $facility] = makeTenantWithUser(['identity.users.read']);
    establish($tenant->id, $facility->id);

    expect(PermissionRegistry::has('made.up.key'))->toBeFalse();

    Tenancy::run($tenant->id, function () use ($user): void {
        expect($user->can('made.up.key'))->toBeFalse();
    });
});

it('does not grant a facility-scoped permission when the user acts in a different facility', function (): void {
    ['user' => $user, 'tenant' => $tenant, 'facility' => $homeFacility] = makeTenantWithUser();

    // Re-scope the user's single assignment to their home facility only.
    Tenancy::run($tenant->id, function () use ($tenant, $user, $homeFacility): void {
        $roleId = DB::table('idn_roles')->where('tenant_id', $tenant->id)->value('id');
        $permId = (string) Str::uuid7();
        DB::table('idn_permissions')->insert(['id' => $permId, 'key' => 'identity.users.manage', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('idn_permission_role')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'role_id' => $roleId, 'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('idn_access_assignments')->where('user_id', $user->id)->update(['facility_id' => $homeFacility->id]);
    });

    $otherFacility = (string) Str::uuid7();

    // acting in the home facility → granted
    establish($tenant->id, $homeFacility->id);
    Tenancy::run($tenant->id, fn () => expect(app(ScopeChecker::class)->effectivePermissions($user, $homeFacility->id))->toContain('identity.users.manage'));

    // acting in another facility → not granted (fresh context)
    app()->forgetInstance(TenantContext::class);
    establish($tenant->id, $otherFacility);
    Tenancy::run($tenant->id, fn () => expect(app(ScopeChecker::class)->effectivePermissions($user, $otherFacility))->not->toContain('identity.users.manage'));
});

it('reports facility capability state, and never leans on facility type', function (): void {
    ['tenant' => $tenant, 'facility' => $facility] = makeTenantWithUser([], ['LABORATORY']);
    $caps = app(FacilityCapabilities::class);

    Tenancy::run($tenant->id, function () use ($caps, $facility): void {
        expect($caps->has($facility->id, 'LABORATORY'))->toBeTrue();
        expect($caps->has($facility->id, 'THEATRE'))->toBeFalse();
    });
});
