<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;

it('shows all facilities to an organization-scoped administrator with facility-read authority', function (): void {
    $fixture = makeTenantWithUser([
        'admin.workspace.access',
        'organization.facilities.read',
        'organization.facilities.manage',
    ]);
    TenantContextScope::run(
        $fixture['tenant']->id,
        null,
        fn (): Facility => Facility::factory()->forTenant($fixture['tenant']->id, $fixture['organization']->id)->create(),
    );

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
    $fixture['user']->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();

    expect(app(ScopeChecker::class)->effectivePermissions($fixture['user'], $fixture['facility']->id))
        ->toContain('admin.workspace.access', 'organization.facilities.read', 'organization.facilities.manage');

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        $this->actingAs($fixture['user'])
            ->withSession(['active_facility_id' => $fixture['facility']->id])
            ->get(route('admin.workspace.organization'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Organization/Index')
                ->where('totalFacilities', 2)
                ->where('activeFacilities', 2)
                ->where('canManage', true)
                ->has('facilities', 2)
                ->where('facilities.0.organizationName', $fixture['organization']->name)
                ->where('facilities.1.organizationName', $fixture['organization']->name));
    });
});

it('limits a facility-scoped administrator to assigned facilities', function (): void {
    $fixture = makeTenantWithUser([
        'admin.workspace.access',
        'organization.facilities.read',
    ]);
    TenantContextScope::run(
        $fixture['tenant']->id,
        null,
        fn (): Facility => Facility::factory()->forTenant($fixture['tenant']->id, $fixture['organization']->id)->create(),
    );
    $fixture['user']->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        $this->actingAs($fixture['user'])
            ->get(route('admin.workspace.organization'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Organization/Index')
                ->where('totalFacilities', 1)
                ->where('canManage', false)
                ->where('facilities.0.id', $fixture['facility']->id));
    });
});

it('denies users without organization facility-read authority', function (): void {
    $fixture = makeTenantWithUser(['admin.workspace.access']);
    $fixture['user']->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        $this->actingAs($fixture['user'])
            ->get(route('admin.workspace.organization'))
            ->assertForbidden();
    });
});
