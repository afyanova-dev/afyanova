<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\ItemLevel;
use App\Domains\Organization\Domain\Model\Facility;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Low-stock alerts (Phase 8): a minimum level per item PER FACILITY, because
 * the item master is shared but "running low" is not. Only items with a level
 * are tracked, expired stock does not count as available, and the screen never
 * moves stock.
 */

/** Freeze "today" so expired-stock arithmetic is exact. */
function freezeLowStockClock(): void
{
    test()->travelTo(CarbonImmutable::parse('2026-10-19 10:00:00'));
}

/** @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} */
function lowStockFixture(?array $permissions = null): array
{
    freezeLowStockClock();

    $fixture = inventoryFixture($permissions ?? [
        'inventory.access',
        'inventory.receipts.create',
        'inventory.items.manage',
    ]);
    test()->withSession(['active_facility_id' => (string) $fixture['facility']->id]);

    return $fixture;
}

/** @param array<string, mixed> $overrides */
function lowStockItem(string $tenantId, string $code, array $overrides = []): Item
{
    return seedInventoryItem($tenantId, array_merge([
        'code' => $code,
        'name' => $code.' item',
    ], $overrides));
}

function lowStockReceive(
    string $tenantId,
    string $facilityId,
    string $actorId,
    Item $item,
    string $locationId,
    string $quantity,
    ?string $batchNumber = null,
    ?string $expiryDate = null,
    ?string $receivedOn = null,
): void {
    dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: $actorId,
        itemId: (string) $item->id,
        locationId: $locationId,
        quantity: $quantity,
        sourceType: 'OPENING_BALANCE',
        batchNumber: $batchNumber,
        expiryDate: $expiryDate,
        occurredAt: $receivedOn,
        reason: $receivedOn !== null ? 'Low-stock fixture (backdated opening balance).' : null,
    ));
}

it('lists tracked items at or below their facility minimum, worst first', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = lowStockFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;
    $locationId = (string) seedStockLocation($tenantId, $facilityId, [
        'code' => 'MAIN',
        'name' => 'Main Store',
    ])->id;

    $out = lowStockItem($tenantId, 'OUT-1');
    $low = lowStockItem($tenantId, 'LOW-1', ['uom' => 'CAPSULE']);
    $equal = lowStockItem($tenantId, 'EQUAL-1', ['uom' => 'VIAL']);
    $ok = lowStockItem($tenantId, 'OK-1');
    $untracked = lowStockItem($tenantId, 'FREE-1');

    lowStockReceive($tenantId, $facilityId, $userId, $low, $locationId, '10');
    lowStockReceive($tenantId, $facilityId, $userId, $equal, $locationId, '5');
    lowStockReceive($tenantId, $facilityId, $userId, $ok, $locationId, '100');
    lowStockReceive($tenantId, $facilityId, $userId, $untracked, $locationId, '3');

    $levels = [
        [(string) $out->id, '5'],
        [(string) $low->id, '20'],
        [(string) $equal->id, '5'],   // exactly at the minimum → already low
        [(string) $ok->id, '10'],     // above the minimum → not listed
    ];

    foreach ($levels as [$itemId, $minLevel]) {
        $this->from('/workspaces/inventory?section=low-stock')
            ->post('/workspaces/inventory/item-levels', [
                'item_id' => $itemId,
                'min_level' => $minLevel,
            ])
            ->assertRedirect('/workspaces/inventory?section=low-stock')
            ->assertSessionHas('status', 'Minimum level saved.');
    }

    $this->get('/workspaces/inventory?section=low-stock')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Index')
            ->has('lowStock', 3)
            ->where('lowStock.0.itemCode', 'OUT-1')
            ->where('lowStock.0.uom', 'TABLET')
            ->where('lowStock.0.onHand', '0.0000')
            ->where('lowStock.0.minLevel', '5.0000')
            ->where('lowStock.0.shortfall', '5.0000')
            ->where('lowStock.0.outOfStock', true)
            ->where('lowStock.0.expired', '0.0000')
            ->where('lowStock.1.itemCode', 'LOW-1')
            ->where('lowStock.1.onHand', '10.0000')
            ->where('lowStock.1.shortfall', '10.0000')
            ->where('lowStock.1.outOfStock', false)
            ->where('lowStock.2.itemCode', 'EQUAL-1')
            ->where('lowStock.2.shortfall', '0.0000')
            ->has('itemLevels', 4)
            ->where('canManageLevels', true));

    $this->assertDatabaseHas('inv_item_levels', [
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'item_id' => (string) $low->id,
        'min_level' => 20,
    ]);
    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'action' => 'inventory.item_level_set',
        'target_id' => (string) $low->id,
    ]);
});

it('does not count expired stock as available and reports it separately', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = lowStockFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;
    $locationId = (string) seedStockLocation($tenantId, $facilityId, [
        'code' => 'PHARMACY',
        'name' => 'Pharmacy Store',
    ])->id;

    $item = lowStockItem($tenantId, 'AMOX-500', [
        'uom' => 'CAPSULE',
        'track_batch' => true,
        'track_expiry' => true,
    ]);

    lowStockReceive($tenantId, $facilityId, $userId, $item, $locationId, '10', 'EXPIRED-1', '2026-10-01', '2026-09-01');
    lowStockReceive($tenantId, $facilityId, $userId, $item, $locationId, '3', 'FRESH-1', '2027-03-01', '2026-09-01');

    $this->post('/workspaces/inventory/item-levels', [
        'item_id' => (string) $item->id,
        'min_level' => '5',
    ])->assertSessionHas('status', 'Minimum level saved.');

    $this->get('/workspaces/inventory?section=low-stock')
        ->assertInertia(fn (Assert $page) => $page
            ->has('lowStock', 1)
            ->where('lowStock.0.itemCode', 'AMOX-500')
            ->where('lowStock.0.onHand', '3.0000')
            ->where('lowStock.0.expired', '10.0000')
            ->where('lowStock.0.minLevel', '5.0000')
            ->where('lowStock.0.shortfall', '2.0000')
            ->where('lowStock.0.outOfStock', false));
});

it('keeps levels per facility even though the item master is shared', function (): void {
    ['tenant' => $tenant, 'organization' => $organization] = lowStockFixture();
    $tenantId = (string) $tenant->id;

    $item = lowStockItem($tenantId, 'SHARED-1');

    $this->post('/workspaces/inventory/item-levels', [
        'item_id' => (string) $item->id,
        'min_level' => '10',
    ])->assertSessionHas('status', 'Minimum level saved.');

    $otherFacility = TenantContextScope::run($tenantId, null, fn () => Facility::factory()
        ->forTenant($tenantId, (string) $organization->id)
        ->create());
    TenantContextScope::run($tenantId, null, fn () => ItemLevel::query()->create([
        'facility_id' => (string) $otherFacility->id,
        'item_id' => (string) $item->id,
        'min_level' => '99',
    ]));

    $this->get('/workspaces/inventory?section=low-stock')
        ->assertInertia(fn (Assert $page) => $page
            ->has('lowStock', 1)
            ->where('lowStock.0.itemCode', 'SHARED-1')
            ->where('lowStock.0.minLevel', '10.0000')
            ->has('itemLevels', 1)
            ->where('itemLevels.0.minLevel', '10.0000'));

    expect(TenantContextScope::run($tenantId, null, fn () => ItemLevel::query()
        ->where('item_id', (string) $item->id)
        ->count()))->toBe(2);
});

it('stops tracking an item when the level is cleared', function (): void {
    ['tenant' => $tenant, 'facility' => $facility] = lowStockFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $item = lowStockItem($tenantId, 'CLEAR-1');

    $this->post('/workspaces/inventory/item-levels', [
        'item_id' => (string) $item->id,
        'min_level' => '25',
    ])->assertSessionHas('status', 'Minimum level saved.');

    $this->get('/workspaces/inventory?section=low-stock')
        ->assertInertia(fn (Assert $page) => $page->has('lowStock', 1)->has('itemLevels', 1));

    $this->post('/workspaces/inventory/item-levels', [
        'item_id' => (string) $item->id,
        'min_level' => '0',
    ])->assertSessionHas('status', 'Minimum level saved.');

    $this->assertDatabaseMissing('inv_item_levels', [
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'item_id' => (string) $item->id,
    ]);
    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'action' => 'inventory.item_level_cleared',
        'target_id' => (string) $item->id,
    ]);

    $this->get('/workspaces/inventory?section=low-stock')
        ->assertInertia(fn (Assert $page) => $page->has('lowStock', 0)->has('itemLevels', 0));
});

it('requires the item-master permission to change a level', function (): void {
    ['tenant' => $tenant] = lowStockFixture(['inventory.access']);
    $tenantId = (string) $tenant->id;

    $item = lowStockItem($tenantId, 'NOPERM-1');

    $this->post('/workspaces/inventory/item-levels', [
        'item_id' => (string) $item->id,
        'min_level' => '5',
    ])->assertForbidden();

    $this->get('/workspaces/inventory?section=low-stock')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('lowStock', 0)
            ->where('canManageLevels', false));
});

it('refuses to set a level outside a facility context', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'user' => $user, 'role_id' => $roleId] = lowStockFixture();
    $tenantId = (string) $tenant->id;

    // Make the role general-scoped so an explicit General context is possible,
    // then clear the facility selection. A level has no meaning without a
    // facility, so the write must be refused.
    scopeInventoryUserToOrganization($tenantId, (string) $organization->id, (string) $user->id, $roleId);

    $item = lowStockItem($tenantId, 'GENERAL-1');

    $this->withSession(['active_facility_id' => null, 'facility_context_mode' => 'general'])
        ->post('/workspaces/inventory/item-levels', [
            'item_id' => (string) $item->id,
            'min_level' => '5',
        ])
        ->assertForbidden();
});

it('validates the item and the minimum level', function (): void {
    ['tenant' => $tenant] = lowStockFixture();
    $tenantId = (string) $tenant->id;

    $item = lowStockItem($tenantId, 'VALID-1');

    $this->post('/workspaces/inventory/item-levels', [
        'item_id' => (string) $item->id,
        'min_level' => '-1',
    ])->assertSessionHasErrors('min_level');

    $this->post('/workspaces/inventory/item-levels', [
        'item_id' => (string) $item->id,
        'min_level' => '1.23456',
    ])->assertSessionHasErrors('min_level');

    $this->post('/workspaces/inventory/item-levels', [
        'item_id' => (string) $item->id,
        'min_level' => 'not-a-number',
    ])->assertSessionHasErrors('min_level');

    $this->post('/workspaces/inventory/item-levels', [
        'item_id' => 'not-a-uuid',
        'min_level' => '5',
    ])->assertSessionHasErrors('item_id');

    $this->assertDatabaseCount('inv_item_levels', 0);
});

it('does not chase items that have been retired', function (): void {
    ['tenant' => $tenant] = lowStockFixture();
    $tenantId = (string) $tenant->id;

    $item = lowStockItem($tenantId, 'RETIRED-1', ['active' => false]);

    $this->post('/workspaces/inventory/item-levels', [
        'item_id' => (string) $item->id,
        'min_level' => '5',
    ])->assertSessionHas('status', 'Minimum level saved.');

    $this->get('/workspaces/inventory?section=low-stock')
        ->assertInertia(fn (Assert $page) => $page
            ->has('lowStock', 0)
            ->has('itemLevels', 0));

    // The level row is kept, not deleted: reactivating the item restores it.
    $this->assertDatabaseHas('inv_item_levels', [
        'tenant_id' => $tenantId,
        'item_id' => (string) $item->id,
    ]);
});
