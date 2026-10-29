<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Procurement\Application\Command\CancelPurchaseOrder;
use App\Domains\Procurement\Application\Command\RecordPurchaseOrderReceipt;
use App\Domains\Procurement\Application\Listener\OnStockReceived;
use App\Domains\Procurement\Application\Query\GetOpenPurchaseOrders;
use App\Domains\Procurement\Application\Query\GetPurchaseOrder;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\PoReceipt;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Events\DispatchListenerJob;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, creator: User, supplier: string, item: string, location: string}
 */
function receiptFixture(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $creator] = procurementFixture([
        'procurement.access', 'procurement.orders.create', 'procurement.orders.amend', 'inventory.access', 'inventory.receipts.create',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $supplier = seedSupplier($t);
    $item = seedInventoryItem($t, ['code' => 'GLOVE-M', 'name' => 'Gloves M', 'uom' => 'BOX']);
    $location = seedStockLocation($t, $f);

    return [
        't' => $t, 'f' => $f, 'creator' => $creator,
        'supplier' => (string) $supplier->id, 'item' => (string) $item->id, 'location' => (string) $location->id,
    ];
}

/** @return list<array<string, mixed>> */
function stockLine(string $itemRef, string $qty): array
{
    return [['lineType' => 'STOCK', 'itemRef' => $itemRef, 'quantity' => $qty, 'uom' => 'BOX', 'unitPrice' => '1000']];
}

test('partial then full receipt rolls PARTIALLY_RECEIVED → FULLY_RECEIVED; the reaction emits nothing; idempotency is keyed on the StockReceived eventId', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $s, 'item' => $item, 'location' => $loc] = receiptFixture();
    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $creator->id, $s, stockLine($item, '10'));

    test()->actingAs($creator);
    $job = receiveAgainstPo($t, $f, (string) $creator->id, $poNumber, $item, $loc, '4');

    $po = TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId));
    expect($po->status)->toBe(PurchaseOrder::STATUS_PARTIALLY_RECEIVED)
        ->and((int) $po->version)->toBeGreaterThan(0);

    $line = TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poId)->first());
    expect((string) $line->received_qty)->toBe('4.0000');

    $receipt = TenantContextScope::run($t, null, fn () => PoReceipt::query()->where('po_id', $poId)->first());
    expect($receipt->po_line_id)->toBe((string) $line->id)
        ->and((string) $receipt->quantity)->toBe('4.0000')
        ->and($receipt->source_type)->toBe('PROCUREMENT');

    // The reaction is a PURE PROJECTION — no PurchaseOrder* projection event is emitted.
    $projectionEvents = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', 'like', '%PurchaseOrder%Received%')->count());
    expect($projectionEvents)->toBe(0);

    // idempotency row keyed on RecordPurchaseOrderReceipt + the StockReceived eventId
    $sourceEventId = (string) $receipt->source_event_id;
    test()->assertDatabaseHas('shared_idempotency_keys', [
        'tenant_id' => $t, 'scope' => RecordPurchaseOrderReceipt::class, 'key' => $sourceEventId, 'status' => 'completed',
    ]);

    // redelivery of the same event → no duplicate receipt, no double count
    $job->handle();
    expect(TenantContextScope::run($t, null, fn () => PoReceipt::query()->where('po_id', $poId)->count()))->toBe(1)
        ->and((string) TenantContextScope::run($t, null, fn () => PoLine::query()->find($line->id)->received_qty))->toBe('4.0000');

    // the remaining 6 → FULLY_RECEIVED
    receiveAgainstPo($t, $f, (string) $creator->id, $poNumber, $item, $loc, '6');
    expect(TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId)->status))
        ->toBe(PurchaseOrder::STATUS_FULLY_RECEIVED);
});

test('over-receipt is recorded faithfully and reaches FULLY_RECEIVED, never blocked', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $s, 'item' => $item, 'location' => $loc] = receiptFixture();
    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $creator->id, $s, stockLine($item, '10'));

    test()->actingAs($creator);
    receiveAgainstPo($t, $f, (string) $creator->id, $poNumber, $item, $loc, '13');

    $line = TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poId)->first());
    expect((string) $line->received_qty)->toBe('13.0000')
        ->and((string) $line->ordered_qty)->toBe('10.0000');
    expect(TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId)->status))
        ->toBe(PurchaseOrder::STATUS_FULLY_RECEIVED);
});

test('GetPurchaseOrder nests receipts[]; GetOpenPurchaseOrders includes a PARTIALLY_RECEIVED PO', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $s, 'item' => $item, 'location' => $loc] = receiptFixture();
    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $creator->id, $s, stockLine($item, '10'));

    test()->actingAs($creator);
    receiveAgainstPo($t, $f, (string) $creator->id, $poNumber, $item, $loc, '4');

    $summary = queryProc($t, $f, new GetPurchaseOrder($poId));
    expect($summary->status)->toBe(PurchaseOrder::STATUS_PARTIALLY_RECEIVED)
        ->and($summary->receipts)->toHaveCount(1)
        ->and($summary->receipts[0]->quantity)->toBe('4.0000')
        ->and($summary->lines[0]->receivedQty)->toBe('4.0000');

    $open = queryProc($t, $f, new GetOpenPurchaseOrders($f));
    expect(collect($open)->pluck('purchaseOrderId')->all())->toContain($poId);
});

test('an unmatched poRef is logged and swallowed — no receipt row, PO untouched, never dead-lettered', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'item' => $item, 'location' => $loc] = receiptFixture();
    test()->actingAs($creator);

    receiveAgainstPo($t, $f, (string) $creator->id, 'PO-DOES-NOT-EXIST', $item, $loc, '5');

    expect(TenantContextScope::run($t, null, fn () => PoReceipt::query()->count()))->toBe(0);
    expect(Tenancy::run($t, fn () => DB::table('shared_dead_letters')->count()))->toBe(0);
});

test('a receipt for an item not on any PO line is recorded with po_line_id = null; no line / status change', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $s, 'item' => $item, 'location' => $loc] = receiptFixture();
    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $creator->id, $s, stockLine($item, '10'));

    $otherItem = (string) seedInventoryItem($t, ['code' => 'SYRINGE'])->id;
    test()->actingAs($creator);
    receiveAgainstPo($t, $f, (string) $creator->id, $poNumber, $otherItem, $loc, '3');

    $receipt = TenantContextScope::run($t, null, fn () => PoReceipt::query()->where('po_id', $poId)->first());
    expect($receipt)->not->toBeNull()->and($receipt->po_line_id)->toBeNull();

    $po = TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId));
    expect($po->status)->toBe(PurchaseOrder::STATUS_ISSUED);
    expect((string) TenantContextScope::run($t, null, fn () => PoLine::query()->where('po_id', $poId)->first()->received_qty))->toBe('0.0000');
});

test('a receipt against a CANCELLED PO is logged and swallowed', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $s, 'item' => $item, 'location' => $loc] = receiptFixture();
    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $creator->id, $s, stockLine($item, '10'));

    test()->actingAs($creator);
    dispatchProc($t, $f, new CancelPurchaseOrder($poId, (string) $creator->id, 'Supplier failed'));

    receiveAgainstPo($t, $f, (string) $creator->id, $poNumber, $item, $loc, '5');

    expect(TenantContextScope::run($t, null, fn () => PoReceipt::query()->where('po_id', $poId)->count()))->toBe(0);
    expect(TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId)->status))
        ->toBe(PurchaseOrder::STATUS_CANCELLED);
});

test('another tenant cannot roll this PO — the poRef resolves only within the tenant (RLS is the sole guard)', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $s, 'item' => $item] = receiptFixture();
    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $creator->id, $s, stockLine($item, '10'));

    // tenant B dispatches the system command directly with tenant A's PO number
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = procurementFixture(['procurement.access']);
    $tb = (string) $tenantB->id;
    test()->actingAs($userB);

    TenantContextScope::run($tb, (string) $facilityB->id, fn () => app(CommandBus::class)->dispatch(new RecordPurchaseOrderReceipt(
        poNumber: $poNumber, sourceEventId: (string) Str::uuid7(), itemRef: $item,
        batchRef: null, locationRef: (string) Str::uuid7(), movementId: (string) Str::uuid7(),
        movementNumber: 'MOV-00099999', quantity: '5', uom: 'BOX', facilityId: (string) $facilityB->id,
        sourceType: 'PROCUREMENT', occurredAt: now()->toIso8601String(),
    )));

    expect(TenantContextScope::run($t, null, fn () => PurchaseOrder::query()->find($poId)->status))
        ->toBe(PurchaseOrder::STATUS_ISSUED);
    expect(TenantContextScope::run($t, null, fn () => PoReceipt::query()->count()))->toBe(0);
});

test('pcr_po_receipts is append-only at the database — UPDATE / DELETE affect 0 rows', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'supplier' => $s, 'item' => $item, 'location' => $loc] = receiptFixture();
    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $creator->id, $s, stockLine($item, '10'));
    test()->actingAs($creator);
    receiveAgainstPo($t, $f, (string) $creator->id, $poNumber, $item, $loc, '4');

    $policies = DB::table('pg_policies')->where('tablename', 'pcr_po_receipts')->pluck('policyname')->all();
    expect($policies)->toContain('pcr_po_receipts_no_update')->and($policies)->toContain('pcr_po_receipts_no_delete');

    $raw = DB::connection('pgsql_raw');
    $raw->transaction(function () use ($raw, $t, $poId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$t]);
        expect($raw->table('pcr_po_receipts')->where('po_id', $poId)->update(['quantity' => 999]))->toBe(0);
        expect($raw->table('pcr_po_receipts')->where('po_id', $poId)->delete())->toBe(0);
    });
});

test('a StockReceived with no poRef is ignored by the listener', function () {
    ['t' => $t, 'f' => $f, 'creator' => $creator, 'item' => $item, 'location' => $loc] = receiptFixture();
    Queue::fake([DispatchListenerJob::class]);

    TenantContextScope::run($t, $f, fn () => app(CommandBus::class)->dispatch(new ReceiveStock(
        movementId: (string) Str::uuid7(), actorId: (string) $creator->id,
        itemId: $item, locationId: $loc, quantity: '5', sourceType: 'DONATION',
    )));
    test()->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    $job = collect(Queue::pushed(DispatchListenerJob::class))
        ->first(fn (DispatchListenerJob $j): bool => $j->listenerClass === OnStockReceived::class);
    $job->handle();

    expect(TenantContextScope::run($t, null, fn () => PoReceipt::query()->count()))->toBe(0);
});
