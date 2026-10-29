<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Queueing;

use App\Domains\Identity\Domain\Model\Permission;
use App\Domains\Identity\Domain\Model\Role;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function syncTestRole(string $tenantId): Role
{
    return TenantContextScope::run($tenantId, null, fn (): Role => Role::query()->firstOrCreate(
        ['tenant_id' => $tenantId, 'key' => 'receptionist'],
        ['name' => 'Receptionist', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY'],
    ));
}

function roleQueueKeys(Role $role): array
{
    return $role->permissions()->where('idn_permissions.key', 'like', 'queue.%')->pluck('idn_permissions.key')->all();
}

test('the sync command attaches missing queue keys and is idempotent', function (): void {
    $fixture = queueingFixture(['queue.access']);
    $tenantId = (string) $fixture['tenant']->id;
    $role = syncTestRole($tenantId);

    $this->artisan('queue:sync-role-permissions', ['--tenant' => $tenantId])
        ->assertSuccessful();

    $keys = TenantContextScope::run($tenantId, null, fn (): array => roleQueueKeys($role->refresh()));
    sort($keys);

    expect($keys)->toBe([
        'queue.access',
        'queue.ticket.call',
        'queue.ticket.hold',
        'queue.ticket.place',
        'queue.ticket.service',
        'queue.ticket.skip',
        'queue.ticket.transfer',
        'queue.ticket.view',
    ]);

    // Second run attaches nothing.
    $this->artisan('queue:sync-role-permissions', ['--tenant' => $tenantId])
        ->expectsOutputToContain('Attached 0 missing permission grant(s); removed 0 unauthorized queue-management grant(s).')
        ->assertSuccessful();
});

test('dry run reports without writing', function (): void {
    $fixture = queueingFixture(['queue.access']);
    $tenantId = (string) $fixture['tenant']->id;
    syncTestRole($tenantId);

    $this->artisan('queue:sync-role-permissions', ['--tenant' => $tenantId, '--dry-run' => true])
        ->expectsOutputToContain('Dry run:')
        ->assertSuccessful();

    $role = TenantContextScope::run(
        $tenantId,
        null,
        fn (): Role => Role::query()->where('key', 'receptionist')->firstOrFail(),
    );

    expect(TenantContextScope::run($tenantId, null, fn (): array => roleQueueKeys($role)))->toBe([]);
});

test('sync removes queue definition management from roles that are not authorized to manage queues', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $role = syncTestRole($tenantId);

    TenantContextScope::run($tenantId, null, function () use ($role): void {
        $permission = Permission::query()
            ->firstOrCreate(
                ['key' => 'queue.definition.manage'],
                ['layer' => 'ACTION'],
            );
        $role->permissions()->syncWithoutDetaching([
            $permission->id => ['id' => (string) Str::uuid7(), 'tenant_id' => $role->tenant_id],
        ]);
    });

    $this->artisan('queue:sync-role-permissions', ['--tenant' => $tenantId])
        ->expectsOutputToContain('removed 1 unauthorized queue-management grant(s).')
        ->assertSuccessful();

    $keys = TenantContextScope::run($tenantId, null, fn (): array => roleQueueKeys($role->refresh()));
    expect($keys)->not->toContain('queue.definition.manage');
});
