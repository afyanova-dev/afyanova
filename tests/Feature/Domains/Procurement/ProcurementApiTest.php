<?php

declare(strict_types=1);

use App\Domains\Procurement\Contracts\Dto\GoodsReceiptPurchaseOrderRef;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderLineRef;
use App\Domains\Procurement\Contracts\ProcurementApi;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Phase 7 — the ProcurementApi read the Inventory PO-aware receiving surface
 * validates against (frontend-foundation-inventory-operations.md Phase 7).
 */

/** @return array{t: string, f: string, user: mixed, supplier: string, item: string} */
function papiFixture(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = procurementFixture([
        'procurement.access', 'procurement.orders.create', 'procurement.orders.approve',
        'procurement.orders.issue', 'procurement.orders.amend',
        'inventory.access', 'inventory.receipts.create',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    return [
        't' => $t, 'f' => $f, 'user' => $user,
        'supplier' => (string) seedSupplier($t)->id,
        'item' => (string) seedInventoryItem($t, ['code' => 'GLOVE-M', 'name' => 'Gloves M', 'uom' => 'BOX'])->id,
    ];
}

/** @return list<array<string, mixed>> */
function papiStockLine(string $itemRef, string $qty): array
{
    return [['lineType' => 'STOCK', 'itemRef' => $itemRef, 'quantity' => $qty, 'uom' => 'BOX', 'unitPrice' => '1000']];
}

test('getOpenPurchaseOrders returns an open PO reduced to its OPEN STOCK lines with a computed remainingQty', function () {
    ['t' => $t, 'f' => $f, 'user' => $user, 'supplier' => $s, 'item' => $item] = papiFixture();
    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $user->id, $s, [
        ...papiStockLine($item, '10'),
        ['lineType' => 'SERVICE', 'description' => 'Delivery service', 'quantity' => '1', 'uom' => 'EACH', 'unitPrice' => '500'],
    ]);

    test()->actingAs($user);

    $open = TenantContextScope::run($t, $f, fn () => app(ProcurementApi::class)->getOpenPurchaseOrders($f));

    expect($open)->toHaveCount(1);
    $po = $open[0];
    expect($po->purchaseOrderId)->toBe($poId)
        ->and($po->poNumber)->toBe($poNumber)
        ->and($po->facilityId)->toBe($f)
        ->and($po->status)->toBe(PurchaseOrder::STATUS_ISSUED)
        // The SERVICE line never crosses the boundary.
        ->and($po->lines)->toHaveCount(1);

    $line = $po->lines[0];
    expect($line)->toBeInstanceOf(OpenPurchaseOrderLineRef::class)
        ->and($line->itemRef)->toBe($item)
        ->and($line->orderedQty)->toBe('10.0000')
        ->and($line->receivedQty)->toBe('0.0000')
        ->and($line->remainingQty)->toBe('10.0000')
        ->and($line->uom)->toBe('BOX');
});

test('goodsReceiptPurchaseOrder exposes only the facility PO and line facts required by Inventory', function () {
    ['t' => $t, 'f' => $f, 'user' => $user, 'supplier' => $s, 'item' => $item] = papiFixture();
    [, $poNumber] = issuePurchaseOrder($t, $f, (string) $user->id, $s, papiStockLine($item, '10'));
    test()->actingAs($user);

    $purchaseOrder = TenantContextScope::run($t, $f, fn () => app(ProcurementApi::class)
        ->goodsReceiptPurchaseOrder($f, $poNumber));

    expect($purchaseOrder)
        ->toBeInstanceOf(GoodsReceiptPurchaseOrderRef::class)
        ->and($purchaseOrder->poNumber)->toBe($poNumber)
        ->and($purchaseOrder->facilityId)->toBe($f)
        ->and($purchaseOrder->lines)->toHaveCount(1)
        ->and($purchaseOrder->lines[0]->itemRef)->toBe($item)
        ->and($purchaseOrder->lines[0]->unit)->toBe('BOX')
        ->and($purchaseOrder->lines[0]->unitPrice)->toBe('1000.00');
    expect(TenantContextScope::run($t, $f, fn () => app(ProcurementApi::class)
        ->goodsReceiptPurchaseOrder($f, 'PO-NOT-FOUND')))->toBeNull();
});

test('a PO drops out of the open set once fully received; a partial receipt lowers remainingQty', function () {
    ['t' => $t, 'f' => $f, 'user' => $user, 'supplier' => $s, 'item' => $item] = papiFixture();
    $location = (string) seedStockLocation($t, $f)->id;
    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $user->id, $s, papiStockLine($item, '10'));

    test()->actingAs($user);
    receiveAgainstPo($t, $f, (string) $user->id, $poNumber, $item, $location, '4');

    test()->actingAs($user);
    $open = TenantContextScope::run($t, $f, fn () => app(ProcurementApi::class)->getOpenPurchaseOrders($f));
    expect($open)->toHaveCount(1)
        ->and($open[0]->status)->toBe(PurchaseOrder::STATUS_PARTIALLY_RECEIVED)
        ->and($open[0]->lines[0]->receivedQty)->toBe('4.0000')
        ->and($open[0]->lines[0]->remainingQty)->toBe('6.0000');

    test()->actingAs($user);
    receiveAgainstPo($t, $f, (string) $user->id, $poNumber, $item, $location, '6');

    test()->actingAs($user);
    expect(TenantContextScope::run($t, $f, fn () => app(ProcurementApi::class)->getOpenPurchaseOrders($f)))->toBe([]);
    expect(TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId)->status))
        ->toBe(PurchaseOrder::STATUS_FULLY_RECEIVED);
});

test('getOpenPurchaseOrders is denied without procurement.access (no PO data disclosed)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $outsider] = procurementFixture(['inventory.access']);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    expect(fn () => TenantContextScope::run($t, $f, fn () => app(ProcurementApi::class)->getOpenPurchaseOrders($f)))
        ->toThrow(AuthorizationException::class);
    expect(fn () => TenantContextScope::run($t, $f, fn () => app(ProcurementApi::class)
        ->goodsReceiptPurchaseOrder($f, 'PO-UNKNOWN')))
        ->toThrow(AuthorizationException::class);
});
