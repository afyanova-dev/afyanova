<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Application\Query\GetStockLedger;
use App\Domains\Inventory\Contracts\Dto\StockLedgerSummary;
use App\Domains\Inventory\Domain\Exception\PackEntryInconsistent;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Pack (buying unit) conversion, first slice
 * (`inventory-item-master-spec.md` §7; frontend-foundation-inventory-operations.md
 * Phase 8): an item may declare a default buying unit (BOX = 100), and a receipt
 * records the unit and pack size actually delivered. Stock stays in the base
 * unit — a dispensary buying 8 bottles out of a pack of 20 is normal.
 */
it('records the buying unit on the catalogue item and refuses a half-given pack', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'user' => $user, 'role_id' => $roleId] = inventoryFixture([
        'inventory.access',
        'inventory.items.manage',
    ]);
    $tenantId = (string) $tenant->id;
    scopeInventoryUserToOrganization($tenantId, (string) $organization->id, (string) $user->id, $roleId);

    $this->withSession(['facility_context_mode' => 'general'])
        ->post('/workspaces/inventory/items', [
            'code' => 'AMOX-500',
            'name' => 'Amoxicillin 500 mg capsule',
            'item_type' => 'MEDICATION',
            'uom' => 'CAPSULE',
            'pack_unit' => 'BOX',
            'pack_size' => '100',
        ])
        ->assertSessionHasNoErrors();

    $this->withSession(['facility_context_mode' => 'general'])
        ->post('/workspaces/inventory/items', [
            'code' => 'GLOVE-M',
            'name' => 'Examination gloves medium',
            'item_type' => 'PPE',
            'uom' => 'PAIR',
        ])
        ->assertSessionHasNoErrors();

    $rows = TenantContextScope::run($tenantId, null, fn () => Item::query()
        ->orderBy('code')
        ->get()
        ->mapWithKeys(fn (Item $item): array => [
            (string) $item->code => [$item->pack_unit, $item->pack_size],
        ])
        ->all());

    expect($rows)->toBe([
        'AMOX-500' => ['BOX', '100.0000'],
        'GLOVE-M' => [null, null],
    ]);

    $this->withSession(['facility_context_mode' => 'general'])
        ->get('/workspaces/inventory?section=catalogue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.code', 'AMOX-500')
            ->where('items.0.packUnit', 'BOX')
            ->where('items.0.packSize', '100.0000')
            ->where('items.1.code', 'GLOVE-M')
            ->where('items.1.packUnit', null)
            ->where('items.1.packSize', null));

    $define = fn (array $payload) => $this->withSession(['facility_context_mode' => 'general'])
        ->post('/workspaces/inventory/items', array_merge([
            'code' => 'REJECT-'.Str::random(6),
            'name' => 'Rejected item',
            'item_type' => 'MEDICATION',
            'uom' => 'CAPSULE',
        ], $payload));

    $define(['pack_unit' => 'BOX'])->assertSessionHasErrors('pack_size');
    $define(['pack_size' => '50'])->assertSessionHasErrors('pack_unit');
    $define(['pack_unit' => 'CAPSULE', 'pack_size' => '100'])->assertSessionHasErrors('pack_unit');
    $define(['pack_unit' => 'BOX', 'pack_size' => '1'])->assertSessionHasErrors('pack_size');
    $define(['pack_unit' => 'CRATE', 'pack_size' => '50'])->assertSessionHasErrors('pack_unit');
});

it('receives in the buying unit and records the base quantity and what arrived', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $item = seedInventoryItem($tenantId, ['code' => 'AMOX-500', 'pack_unit' => 'BOX', 'pack_size' => '100']);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'PHARMACY']);

    dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        quantity: '300',
        sourceType: 'OPENING_BALANCE',
        enteredUnit: 'BOX',
        enteredQuantity: '3',
        packSize: '100',
    ));

    $movement = TenantContextScope::run($tenantId, $facilityId, fn () => StockMovement::query()->sole());

    expect($movement->quantity)->toBe('300.0000')
        ->and($movement->entered_unit)->toBe('BOX')
        ->and($movement->entered_quantity)->toBe('3.0000')
        ->and($movement->pack_size)->toBe('100.0000');

    $balance = TenantContextScope::run($tenantId, $facilityId, fn () => StockBalance::query()->sole());
    expect($balance->on_hand_qty)->toBe('300.0000');

    $ledger = queryInv($tenantId, $facilityId, new GetStockLedger((string) $item->id, (string) $location->id));
    expect($ledger)->toBeInstanceOf(StockLedgerSummary::class)
        ->and($ledger->entries[0]->enteredUnit)->toBe('BOX')
        ->and($ledger->entries[0]->enteredQuantity)->toBe('3.0000')
        ->and($ledger->entries[0]->packSize)->toBe('100.0000')
        ->and($ledger->entries[0]->quantity)->toBe('300.0000');

    expect(inventoryReconciles($tenantId, $facilityId, (string) $item->id, (string) $location->id))->toBeTrue();
});

it('records the pack size this delivery came in, even when it differs from the item default', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    // The item says 1 BOX = 100, but this supplier's boxes hold 50.
    $item = seedInventoryItem($tenantId, ['code' => 'AMOX-500', 'pack_unit' => 'BOX', 'pack_size' => '100']);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'PHARMACY']);

    dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        quantity: '150',
        sourceType: 'OPENING_BALANCE',
        enteredUnit: 'BOX',
        enteredQuantity: '3',
        packSize: '50',
    ));

    $movement = TenantContextScope::run($tenantId, $facilityId, fn () => StockMovement::query()->sole());

    expect($movement->quantity)->toBe('150.0000')
        ->and($movement->entered_unit)->toBe('BOX')
        ->and($movement->entered_quantity)->toBe('3.0000')
        ->and($movement->pack_size)->toBe('50.0000');

    $balance = TenantContextScope::run($tenantId, $facilityId, fn () => StockBalance::query()->sole());
    expect($balance->on_hand_qty)->toBe('150.0000');

    // The receipt's size is a record of THIS delivery, not a change to the item.
    $fresh = TenantContextScope::run($tenantId, null, fn () => Item::query()->find($item->id));
    expect((string) $fresh->pack_size)->toBe('100.0000');
});

it('lets a dispensary receive part of a pack in the base unit', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $item = seedInventoryItem($tenantId, [
        'code' => 'NS-500',
        'name' => 'Normal saline 500 ml',
        'uom' => 'BOTTLE',
        'pack_unit' => 'PACK',
        'pack_size' => '20',
    ]);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'STORE']);

    // Eight bottles out of a pack of twenty — no pack note, just the base unit.
    dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        quantity: '8',
        sourceType: 'DONATION',
        enteredUnit: 'BOTTLE',
        enteredQuantity: '8',
    ));

    $movement = TenantContextScope::run($tenantId, $facilityId, fn () => StockMovement::query()->sole());

    expect($movement->quantity)->toBe('8.0000')
        ->and($movement->entered_unit)->toBe('BOTTLE')
        ->and($movement->entered_quantity)->toBe('8.0000')
        ->and($movement->pack_size)->toBeNull();

    // And a whole pack still works on the same item.
    dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        quantity: '40',
        sourceType: 'DONATION',
        enteredUnit: 'PACK',
        enteredQuantity: '2',
        packSize: '20',
    ));

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => StockBalance::query()->sole()->on_hand_qty))
        ->toBe('48.0000');
});

it('refuses a receipt whose pack record disagrees with the base quantity', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $item = seedInventoryItem($tenantId, ['code' => 'AMOX-500', 'pack_unit' => 'BOX', 'pack_size' => '100']);
    $location = seedStockLocation($tenantId, $facilityId, ['code' => 'PHARMACY']);

    $receive = fn (?string $enteredUnit, ?string $enteredQuantity, ?string $packSize = null) => dispatchInv($tenantId, $facilityId, new ReceiveStock(
        movementId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        quantity: '250',
        sourceType: 'OPENING_BALANCE',
        enteredUnit: $enteredUnit,
        enteredQuantity: $enteredQuantity,
        packSize: $packSize,
    ));

    // 3 BOX of 100 is 300, not 250.
    expect(fn () => $receive('BOX', '3', '100'))
        ->toThrow(PackEntryInconsistent::class);

    // A unit the item is neither stocked nor bought in.
    expect(fn () => $receive('PALLET', '1', '250'))
        ->toThrow(PackEntryInconsistent::class);

    // The buying unit without the size actually delivered.
    expect(fn () => $receive('BOX', '2.5'))
        ->toThrow(PackEntryInconsistent::class);

    // Column mismatch: the base unit with a pack size attached.
    expect(fn () => $receive('CAPSULE', '250', '10'))
        ->toThrow(PackEntryInconsistent::class);

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => StockMovement::query()->count()))->toBe(0);

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => StockMovement::query()->count()))->toBe(0);
});

it('defines and clears a buying unit from the console', function (): void {
    ['tenant' => $tenant] = inventoryFixture();
    $tenantId = (string) $tenant->id;

    Artisan::call('inventory:define-item', [
        'action' => 'define',
        '--tenant' => $tenantId,
        '--code' => 'ORS',
        '--name' => 'ORS sachet',
        '--type' => 'SUPPLY',
        '--uom' => 'sachet',
        '--pack-unit' => 'BOX',
        '--pack-size' => '50',
    ]);

    $item = TenantContextScope::run($tenantId, null, fn () => Item::query()->where('code', 'ORS')->sole());
    expect($item->pack_unit)->toBe('BOX')->and($item->pack_size)->toBe('50.0000');

    Artisan::call('inventory:define-item', [
        'action' => 'update',
        '--tenant' => $tenantId,
        '--id' => (string) $item->id,
        '--clear-pack' => true,
    ]);

    $fresh = TenantContextScope::run($tenantId, null, fn () => Item::query()->find($item->id));
    expect($fresh->pack_unit)->toBeNull()
        ->and($fresh->pack_size)->toBeNull()
        ->and((int) $fresh->version)->toBe(2);
});
