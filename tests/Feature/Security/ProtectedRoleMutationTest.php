<?php

declare(strict_types=1);

use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('blocks lifecycle changes to protected role bundles through the Identity command', function (): void {
    ['tenant' => $tenant, 'role_id' => $roleId] = makeTenantWithUser();
    TenantContextScope::run($tenant->id, null, fn () => DB::table('idn_roles')
        ->where('tenant_id', $tenant->id)
        ->where('id', $roleId)
        ->update(['is_protected' => true]));

    $exitCode = Artisan::call('afyanova:identity:role', [
        'action' => 'status',
        '--tenant' => $tenant->id,
        '--key' => 'test_role',
        '--to' => 'SUSPENDED',
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Role test_role is protected');

    TenantContextScope::run($tenant->id, null, function () use ($tenant, $roleId): void {
        expect(DB::table('idn_roles')
            ->where('tenant_id', $tenant->id)
            ->where('id', $roleId)
            ->value('status'))->toBe('ACTIVE');
    });
});

it('blocks permission grants to protected role bundles through the Identity command', function (): void {
    ['tenant' => $tenant, 'role_id' => $roleId] = makeTenantWithUser();
    TenantContextScope::run($tenant->id, null, fn () => DB::table('idn_roles')
        ->where('tenant_id', $tenant->id)
        ->where('id', $roleId)
        ->update(['is_protected' => true]));

    $exitCode = Artisan::call('afyanova:identity:role', [
        'action' => 'grant',
        '--tenant' => $tenant->id,
        '--key' => 'test_role',
        '--permission' => ['identity.users.read'],
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Role test_role is protected');
});

it('blocks reserved role keys and inheritance from protected bundles', function (): void {
    ['tenant' => $tenant, 'role_id' => $roleId] = makeTenantWithUser();
    TenantContextScope::run($tenant->id, null, function () use ($tenant, $roleId): void {
        DB::table('idn_roles')
            ->where('tenant_id', $tenant->id)
            ->where('id', $roleId)
            ->update(['is_protected' => true]);
    });

    $reservedKeyExit = Artisan::call('afyanova:identity:role', [
        'action' => 'create',
        '--tenant' => $tenant->id,
        '--key' => 'tenant_admin',
    ]);
    expect($reservedKeyExit)->toBe(1)
        ->and(Artisan::output())->toContain('reserved for a protected built-in bundle');

    $inheritanceExit = Artisan::call('afyanova:identity:role', [
        'action' => 'create',
        '--tenant' => $tenant->id,
        '--key' => 'custom_child',
        '--parent' => 'test_role',
    ]);
    expect($inheritanceExit)->toBe(1)
        ->and(Artisan::output())->toContain('cannot inherit permissions from a protected role');
});

it('blocks routine assignment of Tenant Admin, Facility Admin, and custom Admin roles', function (): void {
    foreach (['tenant_admin', 'facility_admin'] as $adminRoleKey) {
        ['tenant' => $tenant, 'role_id' => $roleId, 'user' => $user] = makeTenantWithUser();
        TenantContextScope::run($tenant->id, null, fn () => DB::table('idn_roles')
            ->where('tenant_id', $tenant->id)
            ->where('id', $roleId)
            ->update(['key' => $adminRoleKey, 'is_protected' => true]));

        $exitCode = Artisan::call('afyanova:identity:assign', [
            'action' => 'grant',
            '--tenant' => $tenant->id,
            '--user' => $user->id,
            '--role' => $adminRoleKey,
        ]);
        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('Administrative role promotions require');
    }

    ['tenant' => $tenant, 'user' => $user] = makeTenantWithUser(['admin.workspace.access']);
    $exitCode = Artisan::call('afyanova:identity:assign', [
        'action' => 'grant',
        '--tenant' => $tenant->id,
        '--user' => $user->id,
        '--role' => 'test_role',
    ]);
    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Administrative role promotions require');
});
