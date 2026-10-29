<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Domains\Inventory\Domain\Service\StockReconciliationService;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (! function_exists('grantInventoryRole')) {
    /**
     * Assign a second actor a fresh role carrying the given permission keys at
     * a facility (the multi-actor approve/reject tests — §224).
     *
     * @param  list<string>  $keys
     */
    function grantInventoryRole(string $tenantId, string $facilityId, string $userId, array $keys): void
    {
        TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $userId, $keys): void {
            $roleId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $roleId, 'tenant_id' => $tenantId, 'key' => 'inv_role_'.Str::lower(Str::random(6)),
                'name' => 'Inventory', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($keys as $key) {
                $permId = DB::table('idn_permissions')->where('key', $key)->value('id')
                    ?? tap((string) Str::uuid7(), fn (string $id) => DB::table('idn_permissions')->insert([
                        'id' => $id, 'key' => $key, 'layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION',
                        'created_at' => now(), 'updated_at' => now(),
                    ]));

                DB::table('idn_permission_role')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
                    'role_id' => $roleId, 'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('idn_staff_identities')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $userId,
                'is_staff' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('idn_access_assignments')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $userId,
                'role_id' => $roleId, 'facility_id' => $facilityId, 'status' => 'ACTIVE',
                'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }
}

if (! function_exists('dispatchInv')) {
    function dispatchInv(string $tenantId, ?string $facilityId, object $command): mixed
    {
        return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
    }
}

if (! function_exists('queryInv')) {
    function queryInv(string $tenantId, ?string $facilityId, object $query): mixed
    {
        return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch($query));
    }
}

if (! function_exists('inventoryReconciles')) {
    function inventoryReconciles(string $tenantId, ?string $facilityId, string $itemId, string $locationId, ?string $batchId = null): bool
    {
        return TenantContextScope::run($tenantId, $facilityId, fn () => app(StockReconciliationService::class)
            ->reconcile($itemId, $locationId, $batchId)->isBalanced());
    }
}

if (! function_exists('inventoryFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @param  list<string>  $capabilities
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function inventoryFixture(?array $permissions = null, array $capabilities = []): array
    {
        $permissions ??= [
            'inventory.access',
            'inventory.receipts.create',
            'inventory.consumption.record',
            'inventory.adjustments.create',
            'inventory.adjustments.approve',
        ];

        /** @var array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions, $capabilities);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}

if (! function_exists('scopeInventoryUserToOrganization')) {
    function scopeInventoryUserToOrganization(string $tenantId, string $organizationId, string $userId, string $roleId): void
    {
        TenantContextScope::run($tenantId, null, function () use ($organizationId, $userId, $roleId): void {
            DB::table('idn_roles')->where('id', $roleId)->update([
                'key' => 'tenant_admin',
                'scope_type' => 'ORG',
            ]);
            DB::table('idn_access_assignments')
                ->where('user_id', $userId)
                ->where('role_id', $roleId)
                ->update([
                    'facility_id' => null,
                    'organization_id' => $organizationId,
                ]);
        });
    }
}

if (! function_exists('seedInventoryItem')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function seedInventoryItem(string $tenantId, array $overrides = []): Item
    {
        return TenantContextScope::run($tenantId, null, fn () => Item::factory()
            ->forTenant($tenantId)
            ->create($overrides));
    }
}

if (! function_exists('seedStockLocation')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function seedStockLocation(string $tenantId, string $facilityId, array $overrides = []): StockLocation
    {
        return TenantContextScope::run($tenantId, null, fn () => StockLocation::factory()
            ->forTenant($tenantId)
            ->forFacility($facilityId)
            ->create($overrides));
    }
}

if (! function_exists('receiveStock')) {
    /**
     * Dispatch a `ReceiveStock` for a fresh non-batch item at a location and
     * return the ids used.
     *
     * @return array{itemId: string, locationId: string, movementId: string}
     */
    function receiveStock(string $tenantId, string $facilityId, string $actorId, string $quantity = '100'): array
    {
        $item = seedInventoryItem($tenantId);
        $location = seedStockLocation($tenantId, $facilityId);
        $movementId = (string) Str::uuid7();

        TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new ReceiveStock(
            movementId: $movementId,
            actorId: $actorId,
            itemId: (string) $item->id,
            locationId: (string) $location->id,
            quantity: $quantity,
        )));

        return [
            'itemId' => (string) $item->id,
            'locationId' => (string) $location->id,
            'movementId' => $movementId,
        ];
    }
}
