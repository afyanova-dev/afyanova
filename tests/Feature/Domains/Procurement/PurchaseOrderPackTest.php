<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\CreatePurchaseOrder;
use App\Domains\Procurement\Contracts\ProcurementApi;
use App\Domains\Procurement\Domain\Exception\ProcurementLineSetInvalid;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Pack (buying unit) conversion on purchase orders — Step 2
 * (`inventory-item-master-spec.md` §7; frontend-foundation-inventory-operations.md
 * Phase 8): the buyer orders in the supplier's unit ("3 BOX of 100") and the
 * line keeps BOTH that record and the base quantity stock is received against.
 */

/**
 * @return array{t: string, f: string, creator: string, supplier: string, item: string, location: string}
 */
function packOrderFixture(array $itemAttributes = []): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $creator] = procurementFixture([
        'procurement.access',
        'procurement.orders.create',
        'inventory.access',
        'inventory.receipts.create',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $supplier = seedSupplier($t);
    $item = seedInventoryItem($t, array_merge([
        'code' => 'AMOX-500',
        'name' => 'Amoxicillin 500 mg capsule',
        'uom' => 'CAPSULE',
        'pack_unit' => 'BOX',
        'pack_size' => '100',
    ], $itemAttributes));
    $location = seedStockLocation($t, $f);

    return [
        't' => $t,
        'f' => $f,
        'creator' => $creator,
        'supplier' => (string) $supplier->id,
        'item' => (string) $item->id,
        'location' => (string) $location->id,
    ];
}

it('orders in the buying unit, keeps the entered record, and reconciles receipts in base units', function (): void {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $supplier, 'item' => $item, 'location' => $location] = packOrderFixture();

    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $creator->id, $supplier, [[
        'lineType' => 'STOCK',
        'itemRef' => $item,
        'quantity' => '3',
        'unit' => 'BOX',
        'packSize' => '100',
        'uom' => 'CAPSULE',
        'unitPrice' => '10000',
    ]]);

    $line = TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poId)->sole());

    // What the buyer typed, and the base truth the database derives from it.
    expect((string) $line->ordered_qty)->toBe('3.0000')
        ->and((string) $line->unit)->toBe('BOX')
        ->and((string) $line->pack_size)->toBe('100.0000')
        ->and((string) $line->uom)->toBe('CAPSULE')
        ->and((string) $line->ordered_base_qty)->toBe('300.0000');

    // Money stays in the entered unit: 3 boxes at 10,000 — never 300 x 10,000.
    expect($line->lineTotal())->toBe('30000.00');

    // The receiving surface is told base quantities, with the order record for display.
    test()->actingAs($creator);
    $open = TenantContextScope::run($t, $f, fn () => app(ProcurementApi::class)->getOpenPurchaseOrders($f));
    $ref = collect($open)->firstWhere('poNumber', $poNumber);

    expect($ref)->not->toBeNull();
    $lineRef = $ref->lines[0];
    expect($lineRef->orderedQty)->toBe('300.0000')
        ->and($lineRef->remainingQty)->toBe('300.0000')
        ->and($lineRef->uom)->toBe('CAPSULE')
        ->and($lineRef->enteredUnit)->toBe('BOX')
        ->and($lineRef->enteredQty)->toBe('3.0000')
        ->and($lineRef->packSize)->toBe('100.0000');

    // A whole-pack delivery reconciles against the BASE quantity (300 capsules).
    receiveAgainstPo($t, $f, (string) $creator->id, $poNumber, $item, $location, '300');

    $line = TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poId)->sole());
    $po = TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId));

    expect((string) $line->received_qty)->toBe('300.0000')
        ->and($po->status)->toBe(PurchaseOrder::STATUS_FULLY_RECEIVED);
});

it('falls back to the item default pack size and accepts part of a pack', function (): void {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $supplier, 'item' => $item, 'location' => $location] = packOrderFixture([
        'code' => 'NS-500',
        'name' => 'Normal saline 500 ml',
        'uom' => 'BOTTLE',
        'pack_unit' => 'PACK',
        'pack_size' => '20',
    ]);

    // No packSize sent — the item's default (20) is used. 2.5 packs = 50 bottles.
    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $creator->id, $supplier, [[
        'lineType' => 'STOCK',
        'itemRef' => $item,
        'quantity' => '2.5',
        'unit' => 'PACK',
        'unitPrice' => '2000',
    ]]);

    $line = TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poId)->sole());

    expect((string) $line->pack_size)->toBe('20.0000')
        ->and((string) $line->ordered_base_qty)->toBe('50.0000')
        ->and($line->lineTotal())->toBe('5000.00');

    // Deliveries are counted in bottles: 20, then the remaining 30.
    test()->actingAs($creator);
    receiveAgainstPo($t, $f, (string) $creator->id, $poNumber, $item, $location, '20');
    expect(TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId)->status))
        ->toBe(PurchaseOrder::STATUS_PARTIALLY_RECEIVED);

    receiveAgainstPo($t, $f, (string) $creator->id, $poNumber, $item, $location, '30');
    expect(TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId)->status))
        ->toBe(PurchaseOrder::STATUS_FULLY_RECEIVED);
});

it('keeps a base-unit order unchanged and refuses a unit the item is neither stocked nor bought in', function (): void {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $supplier, 'item' => $item] = packOrderFixture();

    // No `unit` — the plain, pre-pack shape still means the base unit.
    [$poId] = issuePurchaseOrder($t, $f, (string) $creator->id, $supplier, [[
        'lineType' => 'STOCK',
        'itemRef' => $item,
        'quantity' => '10',
        'uom' => 'CAPSULE',
        'unitPrice' => '100',
    ]]);

    $line = TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poId)->sole());
    expect((string) $line->unit)->toBe('CAPSULE')
        ->and($line->pack_size)->toBeNull()
        ->and((string) $line->ordered_base_qty)->toBe('10.0000')
        ->and($line->lineTotal())->toBe('1000.00');

    test()->actingAs($creator);

    $create = fn (array $line) => dispatchProc($t, $f, new CreatePurchaseOrder(
        purchaseOrderId: (string) Str::uuid7(),
        actorId: (string) $creator->id,
        facilityId: $f,
        supplierId: $supplier,
        lines: [$line],
    ));

    // A unit nobody declared on the item.
    expect(fn () => $create([
        'lineType' => 'STOCK', 'itemRef' => $item, 'quantity' => '1',
        'unit' => 'CRATE', 'packSize' => '50', 'unitPrice' => '1000',
    ]))->toThrow(ProcurementLineSetInvalid::class);

    // The base unit cannot carry a pack size.
    expect(fn () => $create([
        'lineType' => 'STOCK', 'itemRef' => $item, 'quantity' => '10',
        'unit' => 'CAPSULE', 'packSize' => '10', 'unitPrice' => '100',
    ]))->toThrow(ProcurementLineSetInvalid::class);
});

it('accepts pack fields from the order screen and surfaces a bad unit as a form error', function (): void {
    ['t' => $t, 'f' => $f, 'supplier' => $supplier, 'item' => $item] = packOrderFixture();

    test()->post('/workspaces/procurement/orders', [
        'supplier_id' => $supplier,
        'priority' => 'ROUTINE',
        'lines' => [[
            'item_id' => $item,
            'quantity' => '3',
            'unit' => 'BOX',
            'pack_size' => '100',
            'unit_price' => '10000',
        ]],
    ])->assertRedirect()->assertSessionHas('status');

    $line = TenantContextScope::run($t, null, fn () => PoLine::query()->latest('line_no')->first());
    expect((string) $line->unit)->toBe('BOX')
        ->and((string) $line->pack_size)->toBe('100.0000')
        ->and((string) $line->ordered_base_qty)->toBe('300.0000');

    test()->from('/workspaces/procurement?section=orders')
        ->post('/workspaces/procurement/orders', [
            'supplier_id' => $supplier,
            'priority' => 'ROUTINE',
            'lines' => [[
                'item_id' => $item,
                'quantity' => '1',
                'unit' => 'CRATE',
                'pack_size' => '50',
                'unit_price' => '1000',
            ]],
        ])
        ->assertSessionHasErrors('order');
});
