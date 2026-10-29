<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Procurement workspace → Purchase orders (Shape B).
 *
 * The command handlers already own the lifecycle and the money limit
 * (`PurchaseOrderSelfApprovalLimitTest`). These tests pin the HTTP layer only:
 * what the screen is told it may do, the direct-order form, and the four
 * transitions through the routes.
 */

/**
 * @param  list<string>|null  $permissions
 * @return array{t: string, f: string, user: User, supplier: string, item: string}
 */
function orderHttpFixture(?array $permissions = null): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = procurementFixture($permissions);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $supplier = seedSupplier($t, ['code' => 'ACME-01', 'name' => 'Acme Medical', 'status' => Supplier::STATUS_ACTIVE]);
    $item = seedInventoryItem($t, ['code' => 'PARA-500', 'name' => 'Paracetamol 500mg', 'uom' => 'TABLET']);

    return [
        't' => $t,
        'f' => $f,
        'user' => $user,
        'supplier' => (string) $supplier->id,
        'item' => (string) $item->id,
    ];
}

/**
 * Raise an order through the real route and return its id.
 *
 * @param  array{t: string, f: string, user: User, supplier: string, item: string}  $fixture
 */
function raiseOrder(array $fixture, string $quantity = '10', string $unitPrice = '100'): string
{
    test()->post('/workspaces/procurement/orders', [
        'supplier_id' => $fixture['supplier'],
        'priority' => 'ROUTINE',
        'notes' => 'For the outpatient store',
        'lines' => [[
            'item_id' => $fixture['item'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]],
    ])->assertRedirect()->assertSessionHas('status');

    return (string) TenantContextScope::run(
        $fixture['t'],
        null,
        fn () => PurchaseOrder::query()->latest('recorded_at')->value('id'),
    );
}

/**
 * @param  array{t: string, f: string, user: User, supplier: string, item: string}  $fixture
 */
function orderStatus(array $fixture, string $poId): string
{
    return (string) TenantContextScope::run(
        $fixture['t'],
        null,
        fn () => PurchaseOrder::query()->findOrFail($poId)->status,
    );
}

it('renders the orders screen with the actions the server allows', function (): void {
    $fixture = orderHttpFixture();
    $poId = raiseOrder($fixture);

    $this->get('/workspaces/procurement?section=orders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Index')
            ->where('section', 'orders')
            ->where('canCreateOrders', true)
            ->where('currentUserId', (string) $fixture['user']->id)
            ->has('purchaseOrders', 1)
            ->where('purchaseOrders.0.id', $poId)
            ->where('purchaseOrders.0.status', PurchaseOrder::STATUS_DRAFT)
            ->where('purchaseOrders.0.orderTotal', '1000.00')
            ->where('purchaseOrders.0.lines.0.itemCode', 'PARA-500')
            ->where('purchaseOrders.0.lines.0.itemName', 'Paracetamol 500mg')
            ->where('purchaseOrders.0.lines.0.orderedQty', '10.0000')
            ->where('purchaseOrders.0.lines.0.uom', 'TABLET')
            ->where('purchaseOrders.0.allowedActions', ['submit', 'cancel'])
            ->where('purchaseOrders.0.selfApprovalPermitted', true)
            ->where('effectiveApprovalLimit', '1000000.00')
            ->has('items', 1));
});

it('returns price references through the facility-scoped protected query', function (): void {
    $fixture = orderHttpFixture();

    $this->getJson('/workspaces/procurement/orders/item-prices?'.http_build_query([
        'supplier_id' => $fixture['supplier'],
        'item_id' => $fixture['item'],
    ]))
        ->assertOk()
        ->assertJsonPath('data.itemId', $fixture['item'])
        ->assertJsonPath('data.supplierId', $fixture['supplier']);
});

it('creates a DRAFT order with the item snapshot and the computed total', function (): void {
    $fixture = orderHttpFixture();

    $poId = raiseOrder($fixture, '3', '2500');

    $order = TenantContextScope::run($fixture['t'], null, fn () => PurchaseOrder::query()->findOrFail($poId));
    $line = TenantContextScope::run($fixture['t'], null, fn () => PoLine::query()->where('po_id', $poId)->firstOrFail());

    expect($order->status)->toBe(PurchaseOrder::STATUS_DRAFT)
        ->and((string) $order->facility_id)->toBe($fixture['f'])
        ->and((string) $order->supplier_id)->toBe($fixture['supplier'])
        ->and($order->po_number)->toStartWith('PO-')
        ->and($order->notes)->toBe('For the outpatient store')
        ->and($line->item_code)->toBe('PARA-500')
        ->and($line->item_name)->toBe('Paracetamol 500mg')
        ->and((string) $line->ordered_qty)->toBe('3.0000')
        ->and((string) $line->uom)->toBe('TABLET')
        ->and($line->lineTotal())->toBe('7500.00');
});

it('refuses a supplier that is not active', function (): void {
    $fixture = orderHttpFixture();
    $suspended = seedSupplier($fixture['t'], [
        'code' => 'SUS-01',
        'name' => 'Suspended Ltd',
        'status' => Supplier::STATUS_SUSPENDED,
    ]);

    $this->post('/workspaces/procurement/orders', [
        'supplier_id' => (string) $suspended->id,
        'priority' => 'ROUTINE',
        'lines' => [[
            'item_id' => $fixture['item'],
            'quantity' => '1',
            'unit_price' => '100',
        ]],
    ])->assertRedirect()->assertSessionHasErrors('order');

    expect(TenantContextScope::run($fixture['t'], null, fn () => PurchaseOrder::query()->count()))->toBe(0);
});

it('walks one person through submit, approve and issue when the order is under the limit', function (): void {
    $fixture = orderHttpFixture();
    $poId = raiseOrder($fixture, '1', '100000');

    $this->post('/workspaces/procurement/orders/'.$poId.'/submit')->assertRedirect()->assertSessionHas('status');
    expect(orderStatus($fixture, $poId))->toBe(PurchaseOrder::STATUS_SUBMITTED);

    $this->get('/workspaces/procurement?section=orders')
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseOrders.0.allowedActions', ['approve', 'cancel'])
            ->where('purchaseOrders.0.selfApprovalPermitted', true));

    $this->post('/workspaces/procurement/orders/'.$poId.'/approve')->assertRedirect()->assertSessionHas('status');
    expect(orderStatus($fixture, $poId))->toBe(PurchaseOrder::STATUS_APPROVED);

    $this->post('/workspaces/procurement/orders/'.$poId.'/issue')->assertRedirect()->assertSessionHas('status');
    expect(orderStatus($fixture, $poId))->toBe(PurchaseOrder::STATUS_ISSUED);
});

it('will not let the creator approve their own order above the facility limit', function (): void {
    $fixture = orderHttpFixture();
    configureApprovalLimit($fixture['t'], $fixture['f'], '50000');
    $poId = raiseOrder($fixture, '1', '100000');

    $this->post('/workspaces/procurement/orders/'.$poId.'/submit')->assertRedirect();

    $this->get('/workspaces/procurement?section=orders')
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseOrders.0.selfApprovalPermitted', false)
            ->where('purchaseOrders.0.allowedActions', ['cancel']));

    $this->post('/workspaces/procurement/orders/'.$poId.'/approve')
        ->assertRedirect()
        ->assertSessionHasErrors('order');

    expect(orderStatus($fixture, $poId))->toBe(PurchaseOrder::STATUS_SUBMITTED);
});

it('lets a second person approve and a third issue above the limit', function (): void {
    $fixture = orderHttpFixture();
    configureApprovalLimit($fixture['t'], $fixture['f'], '50000');
    $poId = raiseOrder($fixture, '1', '100000');
    $this->post('/workspaces/procurement/orders/'.$poId.'/submit')->assertRedirect();

    $approver = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole($fixture['t'], $fixture['f'], (string) $approver->id, [
        'procurement.access', 'procurement.orders.approve', 'procurement.orders.issue',
    ]);

    $this->actingAs($approver)
        ->post('/workspaces/procurement/orders/'.$poId.'/approve')
        ->assertRedirect();

    expect(orderStatus($fixture, $poId))->toBe(PurchaseOrder::STATUS_APPROVED);

    // The approver may not issue their own approval above the limit.
    $this->actingAs($approver)
        ->post('/workspaces/procurement/orders/'.$poId.'/issue')
        ->assertRedirect()
        ->assertSessionHasErrors('order');

    expect(orderStatus($fixture, $poId))->toBe(PurchaseOrder::STATUS_APPROVED);

    $issuer = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole($fixture['t'], $fixture['f'], (string) $issuer->id, [
        'procurement.access', 'procurement.orders.issue',
    ]);

    $this->actingAs($issuer)
        ->post('/workspaces/procurement/orders/'.$poId.'/issue')
        ->assertRedirect();

    expect(orderStatus($fixture, $poId))->toBe(PurchaseOrder::STATUS_ISSUED);
});

it('cancels only with a reason', function (): void {
    $fixture = orderHttpFixture();
    $poId = raiseOrder($fixture);

    $this->post('/workspaces/procurement/orders/'.$poId.'/cancel', ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(orderStatus($fixture, $poId))->toBe(PurchaseOrder::STATUS_DRAFT);

    $this->post('/workspaces/procurement/orders/'.$poId.'/cancel', ['reason' => 'Supplier substituted'])
        ->assertRedirect()
        ->assertSessionHas('status');

    $order = TenantContextScope::run($fixture['t'], null, fn () => PurchaseOrder::query()->findOrFail($poId));

    expect($order->status)->toBe(PurchaseOrder::STATUS_CANCELLED)
        ->and($order->cancellation_reason)->toBe('Supplier substituted');
});

it('closes an issued order short only with a reason', function (): void {
    $fixture = orderHttpFixture();
    $poId = raiseOrder($fixture, '10', '100');

    $this->post('/workspaces/procurement/orders/'.$poId.'/submit')->assertRedirect();
    $this->post('/workspaces/procurement/orders/'.$poId.'/approve')->assertRedirect();
    $this->post('/workspaces/procurement/orders/'.$poId.'/issue')->assertRedirect();

    $this->get('/workspaces/procurement?section=orders')
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseOrders.0.status', PurchaseOrder::STATUS_ISSUED)
            ->where('purchaseOrders.0.allowedActions', ['close-short', 'cancel']));

    $this->post('/workspaces/procurement/orders/'.$poId.'/close-short', ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(orderStatus($fixture, $poId))->toBe(PurchaseOrder::STATUS_ISSUED);

    $this->post('/workspaces/procurement/orders/'.$poId.'/close-short', [
        'reason' => 'Supplier confirmed the balance is unavailable',
    ])->assertRedirect()->assertSessionHas('status', 'Purchase order closed short.');

    $order = TenantContextScope::run($fixture['t'], null, fn () => PurchaseOrder::query()->findOrFail($poId));

    expect($order->status)->toBe(PurchaseOrder::STATUS_CLOSED_SHORT)
        ->and($order->closed_short_reason)->toBe('Supplier confirmed the balance is unavailable')
        ->and((string) $order->closed_short_by_user_id)->toBe((string) $fixture['user']->id)
        ->and($order->closed_short_at)->not->toBeNull();

    $line = TenantContextScope::run($fixture['t'], null, fn () => PoLine::query()
        ->where('po_id', $poId)->firstOrFail());

    expect($line->status)->toBe(PoLine::STATUS_CLOSED_SHORT);

    $this->post('/workspaces/procurement/orders/'.$poId.'/close-short', ['reason' => 'Again'])
        ->assertSessionHasErrors('order');
});

it('gives a reader no purchase-order actions and refuses a write', function (): void {
    $fixture = orderHttpFixture(['procurement.access']);

    $buyer = User::factory()->forTenant($fixture['t'])->create();
    grantProcurementRole($fixture['t'], $fixture['f'], (string) $buyer->id, [
        'procurement.access',
        'procurement.orders.create',
        'procurement.orders.approve',
        'procurement.orders.issue',
        'procurement.orders.amend',
    ]);

    $this->actingAs($buyer);
    $poId = raiseOrder($fixture);

    $this->actingAs($fixture['user'])
        ->get('/workspaces/procurement?section=orders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canCreateOrders', false)
            ->where('purchaseOrders.0.allowedActions', []));

    $this->actingAs($fixture['user'])
        ->post('/workspaces/procurement/orders/'.$poId.'/submit')
        ->assertForbidden();
});

it('shows only the orders at the active facility', function (): void {
    $mine = orderHttpFixture();
    $myOrderId = raiseOrder($mine);

    $theirs = orderHttpFixture();
    $theirOrderId = raiseOrder($theirs);

    $this->actingAs($mine['user'])
        ->get('/workspaces/procurement?section=orders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('purchaseOrders', 1)
            ->where('purchaseOrders.0.id', $myOrderId));

    // Another tenant's order is invisible to the command too.
    $this->actingAs($mine['user'])
        ->post('/workspaces/procurement/orders/'.$theirOrderId.'/submit')
        ->assertRedirect()
        ->assertSessionHasErrors('order');

    expect(orderStatus($theirs, $theirOrderId))->toBe(PurchaseOrder::STATUS_DRAFT);
});

it('accepts a 500-line direct order and rejects 501', function (): void {
    $fixture = orderHttpFixture();

    $line = [
        'item_id' => $fixture['item'],
        'quantity' => '1',
        'unit_price' => '100',
    ];

    $this->post('/workspaces/procurement/orders', [
        'supplier_id' => $fixture['supplier'],
        'priority' => 'ROUTINE',
        'lines' => array_fill(0, 500, $line),
    ])->assertRedirect()->assertSessionHas('status');

    $poId = (string) TenantContextScope::run(
        $fixture['t'],
        null,
        fn () => PurchaseOrder::query()->latest('recorded_at')->value('id'),
    );

    expect(TenantContextScope::run($fixture['t'], null, fn () => PoLine::query()
        ->where('po_id', $poId)->count()))->toBe(500);

    $this->post('/workspaces/procurement/orders', [
        'supplier_id' => $fixture['supplier'],
        'priority' => 'ROUTINE',
        'lines' => array_fill(0, 501, $line),
    ])->assertSessionHasErrors('lines');
});
