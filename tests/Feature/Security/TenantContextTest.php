<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 | PHASE 2 gate — application-layer tenant/facility context, proven through the
 | real HTTP path (blueprint.md §7; docs 03 §56; docs 05 §36).
 */

it('redirects an unauthenticated user away from a protected route', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('renders the dashboard for an authenticated, verified user in tenant context', function (): void {
    ['user' => $user] = makeTenantWithUser();

    $this->actingAs($user)->get('/dashboard')
        ->assertOk();
});

it('a user of tenant A cannot see tenant B facility rows — RLS and the global scope both filter', function (): void {
    ['user' => $userA, 'tenant' => $tenantA] = makeTenantWithUser();
    ['facility' => $facilityB] = makeTenantWithUser();

    app(TenantContext::class)->establish($tenantA->id, null);

    Tenancy::run($tenantA->id, function () use ($facilityB): void {
        expect(Facility::query()->count())->toBe(1);                              // only A's own
        expect(Facility::query()->whereKey($facilityB->id)->exists())->toBeFalse(); // B's row invisible
    });
});

it('with no tenant context, the Facility model returns nothing (fail closed)', function (): void {
    makeTenantWithUser();

    expect(Facility::query()->count())->toBe(0);
});

it('rejects a facility_id the user is not assigned to — never widening scope', function (): void {
    ['user' => $userA] = makeTenantWithUser();
    ['facility' => $facilityB] = makeTenantWithUser();

    $this->actingAs($userA)
        ->from('/dashboard')
        ->post('/facility-context', ['facility_id' => $facilityB->id])
        ->assertForbidden();
});

it('accepts a facility the user IS assigned to and stores it in the session', function (): void {
    ['user' => $user, 'facility' => $facility] = makeTenantWithUser();

    $this->actingAs($user)
        ->from('/dashboard')
        ->post('/facility-context', ['facility_id' => $facility->id])
        ->assertRedirect('/dashboard')
        ->assertSessionHas('active_facility_id', $facility->id);
});

it('a switched facility is re-resolved as the active context on the next request', function (): void {
    // regression: ResolveTenantContext must read the (RLS-protected)
    // idn_access_assignments table INSIDE tenant context, not before it.
    ['user' => $user, 'tenant' => $tenant, 'organization' => $organization, 'facility' => $facilityA, 'role_id' => $roleId] = makeTenantWithUser();

    $facilityB = TenantContextScope::run($tenant->id, null, function () use ($tenant, $organization, $user, $roleId) {
        $facility = Facility::factory()->forTenant($tenant->id, (string) $organization->id)->create();
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id,
            'user_id' => $user->id, 'role_id' => $roleId, 'facility_id' => $facility->id,
            'status' => 'ACTIVE', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $facility;
    });

    // two facility assignments → nothing auto-selected; dashboard renders
    // regardless of facility context
    $this->actingAs($user)->get('/dashboard')
        ->assertOk();

    $this->actingAs($user)->post('/facility-context', ['facility_id' => $facilityB->id]);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.contextMode', 'facility')
            ->where('auth.activeFacility', (string) $facilityB->id));
});

it('enters a facility-only user into their only assigned facility without a manual switch', function (): void {
    ['user' => $user, 'facility' => $facility] = makeTenantWithUser(['inventory.access']);

    $response = $this->actingAs($user)->get('/dashboard');
    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.contextMode', 'facility')
        ->where('auth.activeFacility', (string) $facility->id)
        ->where('auth.canUseGeneral', false));
    $response->assertSessionHas('active_facility_id', (string) $facility->id);
});

it('allows an organization administrator to select General and return to a facility', function (): void {
    ['user' => $user, 'tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'role_id' => $roleId] = makeTenantWithUser([
        'inventory.access',
    ]);
    TenantContextScope::run($tenant->id, null, function () use ($organization, $user, $roleId): void {
        DB::table('idn_roles')->where('id', $roleId)->update(['scope_type' => 'ORG']);
        DB::table('idn_access_assignments')
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->update([
                'facility_id' => null,
                'organization_id' => $organization->id,
            ]);
    });

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.contextMode', 'general')
            ->where('auth.activeFacility', null)
            ->where('auth.canUseGeneral', true));

    $this->post('/facility-context', ['facility_id' => $facility->id])
        ->assertSessionHas('facility_context_mode', 'facility');
    $this->post('/facility-context', ['mode' => 'general'])
        ->assertSessionHas('facility_context_mode', 'general')
        ->assertSessionMissing('active_facility_id');
});

it('does not allow a facility-only user to switch to General', function (): void {
    ['user' => $user] = makeTenantWithUser(['inventory.access']);

    $this->actingAs($user)
        ->post('/facility-context', ['mode' => 'general'])
        ->assertForbidden()
        // The request still resolved into the sole assigned facility (the
        // denial only rejects the General switch itself).
        ->assertSessionHas('facility_context_mode', 'facility');
});

it('drops a stale facility selection whose assignment was revoked — never widening scope', function (): void {
    ['user' => $user, 'facility' => $facility] = makeTenantWithUser(['inventory.access']);

    $this->actingAs($user)->post('/facility-context', ['facility_id' => $facility->id]);

    TenantContextScope::run((string) $user->tenant_id, null, function () use ($user, $facility): void {
        DB::table('idn_access_assignments')
            ->where('user_id', $user->id)
            ->where('facility_id', $facility->id)
            ->update(['status' => 'REVOKED']);
    });

    $response = $this->actingAs($user)->get('/dashboard');
    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.activeFacility', null)
        ->where('auth.canUseGeneral', false));
    $response->assertSessionMissing('active_facility_id');
});

it('drops a malformed facility selection instead of treating it as General', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'user' => $user, 'facility' => $facility, 'role_id' => $roleId] = makeTenantWithUser(['inventory.access']);
    // A second assignment keeps the context unresolved after the drop
    // (a solitary assignment would auto-enter its facility).
    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $organization, $user, $roleId): void {
        $second = Facility::factory()->forTenant($tenant->id, (string) $organization->id)->create();
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id,
            'user_id' => $user->id, 'role_id' => $roleId, 'facility_id' => $second->id,
            'status' => 'ACTIVE', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $response = $this->actingAs($user)->withSession(['active_facility_id' => 'not-a-facility'])
        ->get('/dashboard');
    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.contextMode', 'unresolved')
        ->where('auth.activeFacility', null));
    $response->assertSessionMissing('active_facility_id');
});

it('resolves a facility-mode flag without a selection by entering the sole assigned facility', function (): void {
    ['user' => $user, 'facility' => $facility] = makeTenantWithUser(['inventory.access']);

    $response = $this->actingAs($user)->withSession(['facility_context_mode' => 'facility'])
        ->get('/dashboard');
    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.contextMode', 'facility')
        ->where('auth.activeFacility', (string) $facility->id)
        ->where('auth.canUseGeneral', false));
    $response->assertSessionHas('active_facility_id', (string) $facility->id);
});

it('leaves a facility-only user with several assignments unresolved until they choose one', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'user' => $user, 'role_id' => $roleId] = makeTenantWithUser(['inventory.access']);
    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $organization, $user, $roleId): void {
        $second = Facility::factory()->forTenant($tenant->id, (string) $organization->id)->create();
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id,
            'user_id' => $user->id, 'role_id' => $roleId, 'facility_id' => $second->id,
            'status' => 'ACTIVE', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.contextMode', 'unresolved')
            ->where('auth.activeFacility', null)
            ->where('auth.canUseGeneral', false));
});
