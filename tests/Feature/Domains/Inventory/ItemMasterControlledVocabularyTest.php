<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Domain\Exception\InventoryItemReclassificationNotAllowed;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\ValueObject\ItemCategory;
use App\Domains\Inventory\Domain\ValueObject\ItemType;
use App\Domains\Inventory\Domain\ValueObject\UnitOfMeasure;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
 * The Item Master controlled-vocabulary + immutability slice
 * (inventory-item-master-spec.md §4/§5/§6).
 */

it('rejects an item type outside the controlled vocabulary', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
    ]);
    scopeInventoryUserToOrganization((string) $tenant->id, (string) $organization->id, (string) $user->id, $roleId);

    // Item catalogue mutation is General-only (facility-context plan §Inventory).
    $this->withSession(['facility_context_mode' => 'general'])
        ->from('/workspaces/inventory?section=catalogue')
        ->post('/workspaces/inventory/items', [
            'code' => 'BAD-TYPE',
            'name' => 'Bad type item',
            'item_type' => 'MEDICINE',
            'uom' => 'TABLET',
        ])
        ->assertSessionHasErrors('item_type');
});

it('rejects a base unit outside the controlled vocabulary', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
    ]);
    scopeInventoryUserToOrganization((string) $tenant->id, (string) $organization->id, (string) $user->id, $roleId);

    $this->withSession(['facility_context_mode' => 'general'])
        ->from('/workspaces/inventory?section=catalogue')
        ->post('/workspaces/inventory/items', [
            'code' => 'BAD-UOM',
            'name' => 'Bad unit item',
            'item_type' => 'MEDICATION',
            'uom' => 'TABLETS',
        ])
        ->assertSessionHasErrors('uom');
});

it('rejects a category outside the controlled vocabulary', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
    ]);
    scopeInventoryUserToOrganization((string) $tenant->id, (string) $organization->id, (string) $user->id, $roleId);

    $this->withSession(['facility_context_mode' => 'general'])
        ->from('/workspaces/inventory?section=catalogue')
        ->post('/workspaces/inventory/items', [
            'code' => 'BAD-CATEGORY',
            'name' => 'Bad category item',
            'item_type' => 'MEDICATION',
            'uom' => 'TABLET',
            'category' => 'NONSENSE',
        ])
        ->assertSessionHasErrors('category');
});

it('accepts controlled values and normalises case on the way in', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
    ]);
    $tenantId = (string) $tenant->id;
    scopeInventoryUserToOrganization($tenantId, (string) $organization->id, (string) $user->id, $roleId);

    $this->withSession(['facility_context_mode' => 'general'])
        ->from('/workspaces/inventory?section=catalogue')
        ->post('/workspaces/inventory/items', [
            'code' => 'amox-250',
            'name' => 'Amoxicillin 250mg',
            'item_type' => 'medication',
            'uom' => 'capsule',
            'category' => 'antibiotic',
        ])
        ->assertRedirect('/workspaces/inventory?section=catalogue');

    $item = TenantContextScope::run($tenantId, null, fn () => Item::query()
        ->where('code', 'AMOX-250')
        ->firstOrFail());

    expect($item->item_type)->toBe('MEDICATION')
        ->and($item->uom)->toBe('CAPSULE')
        ->and($item->category)->toBe('ANTIBIOTIC');
});

it('exposes the controlled vocabularies to the workspace pickers', function (): void {
    ['facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
    ]);

    $this->actingAs($user)->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/workspaces/inventory')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('itemTypes', ItemType::ALL)
            ->where('unitsOfMeasure', UnitOfMeasure::ALL)
            ->where('itemCategories', ItemCategory::ALL));
});

it('rejects a non-controlled item type or unit on the console command', function (): void {
    ['tenant' => $tenant] = inventoryFixture();
    $t = (string) $tenant->id;

    expect(fn () => Artisan::call('inventory:define-item', [
        'action' => 'define', '--tenant' => $t,
        '--code' => 'BADTYPE', '--name' => 'X', '--type' => 'MEDICINE', '--uom' => 'TABLET',
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => Artisan::call('inventory:define-item', [
        'action' => 'define', '--tenant' => $t,
        '--code' => 'BADUOM', '--name' => 'Y', '--type' => 'MEDICATION', '--uom' => 'TABLETS',
    ]))->toThrow(InvalidArgumentException::class);
});

it('blocks reclassifying item type or base unit once the item has stock history', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    ['itemId' => $itemId] = receiveStock($tenantId, $facilityId, (string) $user->id);

    expect(fn () => Artisan::call('inventory:define-item', [
        'action' => 'update', '--tenant' => $tenantId, '--id' => $itemId, '--type' => 'SUPPLY',
    ]))->toThrow(InventoryItemReclassificationNotAllowed::class);

    expect(fn () => Artisan::call('inventory:define-item', [
        'action' => 'update', '--tenant' => $tenantId, '--id' => $itemId, '--uom' => 'BOX',
    ]))->toThrow(InventoryItemReclassificationNotAllowed::class);
});

it('still allows descriptive edits and no-op reclassifications with stock history', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $item = TenantContextScope::run($tenantId, null, fn () => Item::factory()->forTenant($tenantId)->create([
        'code' => 'RENAME-ME', 'name' => 'Original name', 'item_type' => 'MEDICATION', 'uom' => 'TABLET',
    ]));
    $itemId = (string) $item->id;

    // Give the item stock history through the real receipt path.
    $location = seedStockLocation($tenantId, (string) $facility->id);
    TenantContextScope::run($tenantId, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new ReceiveStock(
            movementId: (string) Str::uuid7(),
            actorId: (string) $user->id,
            itemId: $itemId,
            locationId: (string) $location->id,
            quantity: '5',
        )));

    // Descriptive edit is allowed…
    Artisan::call('inventory:define-item', [
        'action' => 'update', '--tenant' => $tenantId, '--id' => $itemId, '--name' => 'Renamed item',
    ]);
    $fresh = TenantContextScope::run($tenantId, null, fn () => Item::query()->findOrFail($itemId));
    expect($fresh->name)->toBe('Renamed item');

    // …and re-asserting the SAME type is a no-op, not a reclassification.
    Artisan::call('inventory:define-item', [
        'action' => 'update', '--tenant' => $tenantId, '--id' => $itemId, '--type' => 'MEDICATION',
    ]);
    $fresh = TenantContextScope::run($tenantId, null, fn () => Item::query()->findOrFail($itemId));
    expect($fresh->item_type)->toBe('MEDICATION');
});

it('allows reclassifying an item that has no stock history', function (): void {
    ['tenant' => $tenant] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $item = seedInventoryItem($tenantId, ['code' => 'FRESH', 'item_type' => 'SUPPLY', 'uom' => 'BOX']);

    Artisan::call('inventory:define-item', [
        'action' => 'update', '--tenant' => $tenantId, '--id' => (string) $item->id, '--type' => 'EQUIPMENT',
    ]);

    $fresh = TenantContextScope::run($tenantId, null, fn () => Item::query()->findOrFail($item->id));
    expect($fresh->item_type)->toBe('EQUIPMENT');
});
