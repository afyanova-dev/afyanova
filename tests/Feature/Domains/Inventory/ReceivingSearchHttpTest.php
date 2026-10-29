<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * P0-03 — the Inventory receiving picker searches open POs and their lines on
 * the server. A 500-line order must never be injected into the page or loaded
 * whole by a search.
 *
 * @return array{t: string, f: string, orgId: string, user: object, supplier: string, poId: string, poNumber: string, itemIds: list<string>}
 */
function receivingSearchFixture(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access', 'inventory.receipts.create', 'procurement.access', 'procurement.orders.create',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $supplier = (string) seedSupplier($t, ['name' => 'Acme Medical'])->id;

    $items = [];
    $lines = [];
    foreach ([
        ['code' => 'PARA-500', 'name' => 'Paracetamol 500mg'],
        ['code' => 'GLOVE-M', 'name' => 'Gloves Medium'],
        ['code' => 'SYRINGE-5', 'name' => 'Syringe 5ml'],
    ] as $index => $definition) {
        $itemId = (string) seedInventoryItem($t, $definition + ['uom' => 'BOX'])->id;
        $items[] = $itemId;
        $lines[] = [
            'lineType' => 'STOCK',
            'itemRef' => $itemId,
            'quantity' => (string) (($index + 1) * 10),
            'uom' => 'BOX',
            'unitPrice' => '1000',
        ];
    }

    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $user->id, $supplier, $lines);

    return [
        't' => $t,
        'f' => $f,
        'orgId' => (string) $facility->organization_id,
        'user' => $user,
        'supplier' => $supplier,
        'poId' => $poId,
        'poNumber' => $poNumber,
        'itemIds' => $items,
    ];
}

it('searches open purchase orders by number and returns counts without lines', function (): void {
    $fx = receivingSearchFixture();

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->getJson('/workspaces/inventory/receipts/open-orders?q='.urlencode($fx['poNumber']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.purchaseOrderId', $fx['poId'])
        ->assertJsonPath('data.0.poNumber', $fx['poNumber'])
        ->assertJsonPath('data.0.status', 'ISSUED')
        ->assertJsonPath('data.0.openLineCount', 3)
        ->assertJsonPath('data.0.remainingLineCount', 3)
        ->assertJsonPath('data.0.lines', [])
        ->assertJsonPath('meta.total', 1);
});

it('searches open purchase orders by item code and supplier name', function (): void {
    $fx = receivingSearchFixture();

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->getJson('/workspaces/inventory/receipts/open-orders?q=GLOVE-M')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.purchaseOrderId', $fx['poId']);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->getJson('/workspaces/inventory/receipts/open-orders?q=Acme')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.purchaseOrderId', $fx['poId']);
});

it('pages and searches the selected purchase order lines', function (): void {
    $fx = receivingSearchFixture();

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->getJson("/workspaces/inventory/receipts/open-orders/{$fx['poId']}/lines?per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.currentPage', 1)
        ->assertJsonPath('meta.lastPage', 2);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->getJson("/workspaces/inventory/receipts/open-orders/{$fx['poId']}/lines?q=SYRINGE")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.itemCode', 'SYRINGE-5')
        ->assertJsonPath('data.0.remainingQty', '30.0000');

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->getJson("/workspaces/inventory/receipts/open-orders/{$fx['poId']}/lines?q=2")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.lineNo', 2);
});

it('excludes a line that has no remaining quantity', function (): void {
    $fx = receivingSearchFixture();

    TenantContextScope::run($fx['t'], null, fn () => PoLine::query()
        ->where('po_id', $fx['poId'])
        ->where('item_ref', $fx['itemIds'][0])
        ->update(['received_qty' => '10.0000']));

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->getJson("/workspaces/inventory/receipts/open-orders/{$fx['poId']}/lines")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->getJson('/workspaces/inventory/receipts/open-orders?q='.urlencode($fx['poNumber']))
        ->assertOk()
        ->assertJsonPath('data.0.remainingLineCount', 2);
});

it('refuses the receiving search without procurement access', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access', 'inventory.receipts.create',
    ]);

    $this->actingAs($user)->withSession(['active_facility_id' => (string) $facility->id])
        ->getJson('/workspaces/inventory/receipts/open-orders')
        ->assertForbidden();
});

it('does not leak another facility purchase order or its lines', function (): void {
    $fx = receivingSearchFixture();

    $otherFacility = TenantContextScope::run($fx['t'], null, fn () => Facility::factory()
        ->forTenant($fx['t'], $fx['orgId'])
        ->create());
    $otherFacilityId = (string) $otherFacility->id;
    $otherCreator = User::factory()->forTenant($fx['t'])->create();
    grantProcurementRole($fx['t'], $otherFacilityId, (string) $otherCreator->id, [
        'procurement.access', 'procurement.orders.create',
    ]);
    $this->actingAs($otherCreator);

    [$otherPoId, $otherPoNumber] = issuePurchaseOrder(
        $fx['t'],
        $otherFacilityId,
        (string) $otherCreator->id,
        $fx['supplier'],
        [[
            'lineType' => 'STOCK',
            'itemRef' => $fx['itemIds'][0],
            'quantity' => '10',
            'uom' => 'BOX',
            'unitPrice' => '1000',
        ]],
    );

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->getJson("/workspaces/inventory/receipts/open-orders/{$otherPoId}/lines")
        ->assertNotFound();

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->getJson('/workspaces/inventory/receipts/open-orders?q='.urlencode($otherPoNumber))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('refuses the receiving search without inventory receipt permission', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access',
    ]);

    $this->actingAs($user)->withSession(['active_facility_id' => (string) $facility->id])
        ->getJson('/workspaces/inventory/receipts/open-orders')
        ->assertForbidden();
});
