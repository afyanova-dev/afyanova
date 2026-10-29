<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Identity\Contracts\FacilityAdminRoleDefinition;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function syncSeedProtectedRole(string $tenantId, string $key, string $name): string
{
    return TenantContextScope::run($tenantId, null, function () use ($tenantId, $key, $name): string {
        $roleId = (string) Str::uuid7();
        DB::table('idn_roles')->insert([
            'id' => $roleId,
            'tenant_id' => $tenantId,
            'key' => $key,
            'name' => $name,
            'status' => 'ACTIVE',
            'scope_type' => 'FACILITY',
            'is_protected' => true,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $roleId;
    });
}

/**
 * Seed an existing facility-admin role carrying every canonical permission
 * except `procurement.access` — the real "Phase 7 added a key to the definition"
 * state.
 */
function syncSeedFacilityAdmin(string $tenantId): string
{
    $roleId = syncSeedProtectedRole($tenantId, FacilityAdminRoleDefinition::KEY, 'Facility Administrator');

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $roleId): void {
        foreach (FacilityAdminRoleDefinition::PERMISSIONS as $key) {
            if ($key === 'procurement.access' || ! PermissionRegistry::has($key)) {
                continue;
            }

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
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    return $roleId;
}

/** @return list<string> */
function syncRoleKeys(string $tenantId, string $roleId): array
{
    /** @var list<string> $keys */
    $keys = TenantContextScope::run($tenantId, null, fn (): array => DB::table('idn_permission_role as pr')
        ->join('idn_permissions as p', 'p.id', '=', 'pr.permission_id')
        ->where('pr.role_id', $roleId)
        ->pluck('p.key')
        ->all());

    sort($keys);

    return $keys;
}

test('the sync command backfills a newly-added canonical permission onto an existing role', function (): void {
    $tenantId = (string) makeTenantWithUser()['tenant']->id;
    $roleId = syncSeedFacilityAdmin($tenantId);

    expect(syncRoleKeys($tenantId, $roleId))->not->toContain('procurement.access');

    expect(Artisan::call('identity:sync-role-permissions', ['--tenant' => $tenantId]))->toBe(0);

    $expected = collect(FacilityAdminRoleDefinition::PERMISSIONS)
        ->filter(static fn (string $key): bool => PermissionRegistry::has($key))
        ->sort()
        ->values()
        ->all();

    expect(syncRoleKeys($tenantId, $roleId))->toBe($expected);
});

test('the sync command is idempotent', function (): void {
    $tenantId = (string) makeTenantWithUser()['tenant']->id;
    syncSeedFacilityAdmin($tenantId);

    Artisan::call('identity:sync-role-permissions', ['--tenant' => $tenantId]);
    Artisan::call('identity:sync-role-permissions', ['--tenant' => $tenantId]);

    expect(Artisan::output())->toContain('Attached 0 missing permission grant(s).');
});

test('dry run reports without writing', function (): void {
    $tenantId = (string) makeTenantWithUser()['tenant']->id;
    $roleId = syncSeedFacilityAdmin($tenantId);

    Artisan::call('identity:sync-role-permissions', ['--tenant' => $tenantId, '--dry-run' => true]);

    expect(Artisan::output())->toContain('Dry run:')
        ->and(syncRoleKeys($tenantId, $roleId))->not->toContain('procurement.access');
});

test('the sync command audits each reconciled role', function (): void {
    $tenantId = (string) makeTenantWithUser()['tenant']->id;
    $roleId = syncSeedFacilityAdmin($tenantId);

    Artisan::call('identity:sync-role-permissions', ['--tenant' => $tenantId]);

    $events = TenantContextScope::run($tenantId, null, fn () => DB::table('shared_audit_log')
        ->where('action', 'identity.role.definition_reconciled')
        ->where('target_id', $roleId)
        ->get());

    expect($events)->toHaveCount(1);

    $metadata = TenantContextScope::run($tenantId, null, fn (): mixed => DB::table('shared_audit_log')
        ->where('action', 'identity.role.definition_reconciled')
        ->where('target_id', $roleId)
        ->value('metadata'));

    expect(json_decode((string) $metadata, true, 512, JSON_THROW_ON_ERROR))
        ->toEqual([
            'role' => FacilityAdminRoleDefinition::KEY,
            'permissions_added' => ['procurement.access'],
        ]);
});
