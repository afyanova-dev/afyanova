<?php

declare(strict_types=1);

use App\Domains\Inventory\Contracts\Event\GoodsReceiptPosted;
use App\Domains\Inventory\Contracts\Event\StockReceived;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\GoodsReceipt;
use App\Domains\Inventory\Domain\Model\GoodsReceiptLine;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Domains\Inventory\Domain\Model\SupplierReturn;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Shared\Events\DispatchListenerJob;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
 * Phase 1 — one delivery, one GRN, many lines. Only accepted quantity posts to
 * stock; `po_line_id` stays authoritative; a failure leaves no partial receipt.
 */

/** @return list<array<string, mixed>> */
function goodsReceiptPoLine(string $itemRef, string $qty): array
{
    return [['lineType' => 'STOCK', 'itemRef' => $itemRef, 'quantity' => $qty, 'uom' => 'BOX', 'unitPrice' => '1000']];
}

/** @return array<string, mixed> */
function goodsReceiptFixture(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access', 'inventory.receipts.create', 'procurement.access', 'procurement.orders.create',
        'procurement.invoices.match',
    ]);

    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $itemA = (string) seedInventoryItem($t, ['code' => 'GLOVE-M', 'name' => 'Gloves M', 'uom' => 'BOX'])->id;
    $itemB = (string) seedInventoryItem($t, ['code' => 'MASK-S', 'name' => 'Mask S', 'uom' => 'BOX'])->id;
    $location = (string) seedStockLocation($t, $f)->id;
    $supplier = (string) seedSupplier($t)->id;

    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $user->id, $supplier, [
        ['lineType' => 'STOCK', 'itemRef' => $itemA, 'quantity' => '10', 'uom' => 'BOX', 'unitPrice' => '1000'],
        ['lineType' => 'STOCK', 'itemRef' => $itemB, 'quantity' => '5', 'uom' => 'BOX', 'unitPrice' => '2000'],
    ]);

    $lines = TenantContextScope::run($t, null, fn () => PoLine::query()
        ->where('po_id', $poId)->orderBy('line_no')->get());

    test()->actingAs($user);

    return [
        't' => $t, 'f' => $f, 'user' => $user, 'supplier' => $supplier,
        'itemA' => $itemA, 'itemB' => $itemB, 'location' => $location,
        'poId' => $poId, 'poNumber' => $poNumber,
        'lineA' => (string) $lines[0]->id, 'lineB' => (string) $lines[1]->id,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function goodsReceiptLinePayload(string $itemId, string $poLineId, string $qty, array $overrides = []): array
{
    return array_merge([
        'receipt_line_id' => (string) Str::uuid7(),
        'item_id' => $itemId,
        'po_line_id' => $poLineId,
        'entered_unit' => 'BOX',
        'entered_quantity' => $qty,
        'pack_size' => '1',
        'quantity_delivered' => $qty,
        'quantity_rejected' => '0',
        'condition' => 'GOOD',
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $lines
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function goodsReceiptPayload(array $lines, array $overrides = []): array
{
    return array_merge([
        'receipt_id' => (string) Str::uuid7(),
        'idempotency_key' => (string) Str::uuid7(),
        'location_id' => '',
        'source_type' => 'PROCUREMENT',
        'invoice_status' => 'PENDING',
        'invoice_currency' => 'TZS',
        'lines' => $lines,
    ], $overrides);
}

it('posts one GRN with accepted-only stock and projects every line to its exact PO line', function (): void {
    $fx = goodsReceiptFixture();
    Queue::fake([DispatchListenerJob::class]);

    $payload = goodsReceiptPayload([
        goodsReceiptLinePayload($fx['itemA'], $fx['lineA'], '4', ['quantity_rejected' => '1', 'condition' => 'DAMAGED', 'rejection_reason' => 'Torn carton']),
        goodsReceiptLinePayload($fx['itemB'], $fx['lineB'], '3'),
    ], [
        'location_id' => $fx['location'],
        'po_ref' => $fx['poNumber'],
        'supplier_ref' => $fx['supplier'],
        'delivery_note_number' => 'DN-100',
        'invoice_number' => 'INV-100',
    ]);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHas('status', 'Goods receipt posted.')
        ->assertRedirect(route('inventory.index', [
            'receipt_id' => $payload['receipt_id'],
        ]));

    $receipt = TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceipt::query()
        ->whereKey($payload['receipt_id'])->firstOrFail());
    expect($receipt->receipt_number)->toBe('GRN-00000001')
        ->and($receipt->po_ref)->toBe($fx['poNumber'])
        ->and($receipt->invoice_status)->toBe('PENDING')
        ->and($receipt->delivery_note_number)->toBe('DN-100');

    $receiptLines = TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceiptLine::query()
        ->where('receipt_id', $receipt->id)->orderBy('created_at')->get());
    expect($receiptLines)->toHaveCount(2)
        ->and((string) $receiptLines[0]->quantity_delivered)->toBe('4.0000')
        ->and((string) $receiptLines[0]->quantity_rejected)->toBe('1.0000')
        ->and((string) $receiptLines[0]->quantity_accepted)->toBe('3.0000')
        ->and((string) $receiptLines[1]->quantity_accepted)->toBe('3.0000');

    $movements = TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()
        ->where('goods_receipt_ref', $receipt->id)->orderBy('item_id')->get());
    expect($movements)->toHaveCount(2)
        ->and((string) $movements->sum(fn (StockMovement $m): float => (float) $m->quantity))->toBe('6')
        ->and($movements[0]->goods_receipt_ref)->toBe((string) $receipt->id)
        ->and($movements[0]->po_line_ref)->not->toBeNull();

    // One GoodsReceiptPosted batch projection plus the per-line StockReceived
    // facts (whose listener exits immediately when goodsReceiptRef is present).
    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
    $jobs = collect(Queue::pushed(DispatchListenerJob::class))
        ->filter(fn (DispatchListenerJob $j): bool => (
            $j->event instanceof StockReceived
            && $j->event->goodsReceiptRef === (string) $receipt->id
        ) || (
            $j->event instanceof GoodsReceiptPosted
            && $j->event->goodsReceiptId === (string) $receipt->id
        ))
        ->unique(fn (DispatchListenerJob $j): string => $j->event::class.':'.$j->event->eventId)
        ->values();
    expect($jobs)->toHaveCount(3);
    $jobs->each(fn (DispatchListenerJob $j) => $j->handle());

    $poLines = TenantContextScope::run($fx['t'], null, fn () => PoLine::query()
        ->where('po_id', $fx['poId'])->orderBy('line_no')->get());
    expect((string) $poLines[0]->received_qty)->toBe('3.0000')
        ->and((string) $poLines[1]->received_qty)->toBe('3.0000');
});

it('attaches a pending supplier invoice to a posted GRN without changing stock', function (): void {
    $fx = goodsReceiptFixture();
    Queue::fake([DispatchListenerJob::class]);
    $payload = goodsReceiptPayload([
        goodsReceiptLinePayload($fx['itemA'], $fx['lineA'], '4'),
    ], [
        'location_id' => $fx['location'],
        'po_ref' => $fx['poNumber'],
        'supplier_ref' => $fx['supplier'],
    ]);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHasNoErrors();

    $movementCount = TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()->count());
    $this->post(route('inventory.goods-receipts.invoice', $payload['receipt_id']), [
        'invoice_number' => 'INV-2026-041',
        'invoice_date' => '2026-10-20',
        'invoice_currency' => 'TZS',
        'invoice_total' => '4000.00',
    ])->assertSessionHas('status', 'Supplier invoice attached to the goods receipt.');

    $receipt = TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceipt::query()
        ->whereKey($payload['receipt_id'])->firstOrFail());
    expect($receipt->invoice_number)->toBe('INV-2026-041')
        ->and($receipt->invoice_date->toDateString())->toBe('2026-10-20')
        ->and((string) $receipt->invoice_total)->toBe('4000.00')
        ->and($receipt->invoice_status)->toBe(GoodsReceipt::INVOICE_RECEIVED)
        ->and($receipt->match_status)->toBe(GoodsReceipt::MATCH_UNMATCHED)
        ->and(TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()->count()))->toBe($movementCount);
});

it('matches invoice quantities and prices against the receipt snapshot without changing stock', function (): void {
    $fx = goodsReceiptFixture();
    Queue::fake([DispatchListenerJob::class]);
    $payload = goodsReceiptPayload([
        goodsReceiptLinePayload($fx['itemA'], $fx['lineA'], '4', [
            'quantity_rejected' => '1',
            'condition' => 'DAMAGED',
            'rejection_reason' => 'Damaged carton',
        ]),
    ], [
        'location_id' => $fx['location'],
        'po_ref' => $fx['poNumber'],
        'supplier_ref' => $fx['supplier'],
        'invoice_number' => 'INV-MATCH-1',
        'invoice_status' => 'RECEIVED',
        'invoice_total' => '3000.00',
    ]);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHasNoErrors();

    $line = TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceiptLine::query()
        ->where('receipt_id', $payload['receipt_id'])->firstOrFail());
    expect($line->po_unit)->toBe('BOX')
        ->and((string) $line->po_unit_price)->toBe('1000.00')
        ->and((string) $line->invoice_quantity)->toBe('');

    TenantContextScope::run($fx['t'], null, function () use ($fx): void {
        PoLine::query()->whereKey($fx['lineA'])->update(['unit_price' => '1500.00']);
    });
    $movementCount = TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()->count());

    $this->post(route('inventory.goods-receipts.match', $payload['receipt_id']), [
        'lines' => [[
            'receipt_line_id' => $line->id,
            'invoice_quantity' => '3',
            'invoice_unit_price' => '1000.00',
        ]],
    ])->assertSessionHas('status', 'Supplier invoice matched to the goods receipt.');

    $receipt = TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceipt::query()
        ->whereKey($payload['receipt_id'])->firstOrFail());
    $matchedLine = TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceiptLine::query()
        ->whereKey($line->id)->firstOrFail());
    expect($receipt->match_status)->toBe(GoodsReceipt::MATCH_MATCHED)
        ->and($receipt->matched_by_user_id)->toBe((string) $fx['user']->id)
        ->and((string) $matchedLine->invoice_quantity)->toBe('3.0000')
        ->and((string) $matchedLine->invoice_unit_price)->toBe('1000.00')
        ->and((string) $matchedLine->po_unit_price)->toBe('1000.00')
        ->and(TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()->count()))->toBe($movementCount);

    $export = $this->get(route('inventory.goods-receipts.match.export', $payload['receipt_id']))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertDownload('invoice-match-GRN-00000001.csv');
    expect($export->streamedContent())
        ->toContain('INV-MATCH-1')
        ->toContain('3.0000')
        ->toContain('1000.00');
});

it('flags quantity and unit-price differences as an invoice variance', function (): void {
    $fx = goodsReceiptFixture();
    Queue::fake([DispatchListenerJob::class]);
    $payload = goodsReceiptPayload([
        goodsReceiptLinePayload($fx['itemA'], $fx['lineA'], '3'),
    ], [
        'location_id' => $fx['location'],
        'po_ref' => $fx['poNumber'],
        'supplier_ref' => $fx['supplier'],
        'invoice_number' => 'INV-MATCH-2',
        'invoice_status' => 'RECEIVED',
    ]);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHasNoErrors();
    $line = TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceiptLine::query()
        ->where('receipt_id', $payload['receipt_id'])->firstOrFail());
    $movementCount = TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()->count());

    $this->post(route('inventory.goods-receipts.match', $payload['receipt_id']), [
        'lines' => [[
            'receipt_line_id' => $line->id,
            'invoice_quantity' => '2',
            'invoice_unit_price' => '1100.00',
        ]],
    ])->assertSessionHas('status', 'Supplier invoice matched to the goods receipt.');

    $receipt = TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceipt::query()
        ->whereKey($payload['receipt_id'])->firstOrFail());
    expect($receipt->match_status)->toBe(GoodsReceipt::MATCH_VARIANCE)
        ->and(TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()->count()))->toBe($movementCount);
});

it('posts a supplier return as an immutable stock out without editing the GRN', function (): void {
    $fx = goodsReceiptFixture();
    Queue::fake([DispatchListenerJob::class]);
    $payload = goodsReceiptPayload([
        goodsReceiptLinePayload($fx['itemA'], $fx['lineA'], '5'),
    ], [
        'location_id' => $fx['location'],
        'po_ref' => $fx['poNumber'],
        'supplier_ref' => $fx['supplier'],
    ]);
    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHasNoErrors();

    $line = TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceiptLine::query()
        ->where('receipt_id', $payload['receipt_id'])->firstOrFail());
    $movementCount = TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()->count());
    $stockBeforeReturn = TenantContextScope::run($fx['t'], $fx['f'], fn () => StockBalance::query()
        ->where('item_id', $fx['itemA'])->where('location_id', $fx['location'])->firstOrFail()->on_hand_qty);

    $this->post(route('inventory.goods-receipts.returns', $payload['receipt_id']), [
        'return_id' => (string) Str::uuid7(),
        'location_id' => $fx['location'],
        'reason' => 'DAMAGED_AFTER_RECEIPT',
        'lines' => [[
            'receipt_line_id' => $line->id,
            'quantity' => '2',
            'credit_note_ref' => 'CN-55',
            'notes' => 'Carton damaged after being put away.',
        ]],
    ])->assertSessionHas('status', 'Supplier return RET-00000001 posted; stock history was preserved.');

    $return = TenantContextScope::run($fx['t'], $fx['f'], fn () => SupplierReturn::query()
        ->where('receipt_id', $payload['receipt_id'])->firstOrFail());
    $returnLine = TenantContextScope::run($fx['t'], $fx['f'], fn () => DB::table('inv_supplier_return_lines')
        ->where('return_id', $return->id)->first());
    $returnMovement = TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()
        ->where('source_ref', $return->id)->firstOrFail());
    $stockAfterReturn = TenantContextScope::run($fx['t'], $fx['f'], fn () => StockBalance::query()
        ->where('item_id', $fx['itemA'])->where('location_id', $fx['location'])->firstOrFail()->on_hand_qty);
    $receiptUnchanged = TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceiptLine::query()
        ->whereKey($line->id)->firstOrFail());

    expect($return->return_number)->toBe('RET-00000001')
        ->and($return->reason)->toBe('DAMAGED_AFTER_RECEIPT')
        ->and($returnLine->credit_note_ref)->toBe('CN-55')
        ->and($returnMovement->movement_type)->toBe(StockMovement::TYPE_RETURN_OUT)
        ->and($returnMovement->direction)->toBe(StockMovement::DIRECTION_OUT)
        ->and((string) $returnMovement->quantity)->toBe('2.0000')
        ->and((string) $stockBeforeReturn)->toBe('5.0000')
        ->and((string) $stockAfterReturn)->toBe('3.0000')
        ->and((string) $receiptUnchanged->quantity_accepted)->toBe('5.0000')
        ->and(TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()->count()))->toBe($movementCount + 1);

    $this->post(route('inventory.goods-receipts.returns', $payload['receipt_id']), [
        'return_id' => (string) Str::uuid7(),
        'location_id' => $fx['location'],
        'reason' => 'DAMAGED_AFTER_RECEIPT',
        'lines' => [[
            'receipt_line_id' => $line->id,
            'quantity' => '4',
        ]],
    ])->assertSessionHasErrors('supplier_return');
    expect(TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()->count()))->toBe($movementCount + 1);
});

it('posts one GRN and one set of movements when the same receipt is submitted twice', function (): void {
    Queue::fake([DispatchListenerJob::class]);
    $fx = goodsReceiptFixture();

    $payload = goodsReceiptPayload([
        goodsReceiptLinePayload($fx['itemA'], $fx['lineA'], '3'),
    ], [
        'location_id' => $fx['location'],
        'po_ref' => $fx['poNumber'],
        'supplier_ref' => $fx['supplier'],
    ]);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHas('status', 'Goods receipt posted.');

    // A double click / retried Inertia post carries the same receipt_id and
    // idempotency_key; the bus must not mint a second GRN or stock movement.
    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHas('status', 'Goods receipt posted.');

    expect(TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceipt::query()
        ->whereKey($payload['receipt_id'])->count()))->toBe(1)
        ->and(TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceiptLine::query()
            ->where('receipt_id', $payload['receipt_id'])->count()))->toBe(1)
        ->and(TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()
            ->where('source_ref', $payload['receipt_id'])->count()))->toBe(1);
});

it('posts a substitute item and still receives the original PO line', function (): void {
    $fx = goodsReceiptFixture();
    Queue::fake([DispatchListenerJob::class]);

    $line = goodsReceiptLinePayload($fx['itemB'], $fx['lineA'], '2', [
        'condition' => 'WRONG_ITEM',
        'substituted_for_item_id' => $fx['itemA'],
        'condition_notes' => 'Supplier sent the equivalent mask pack instead of gloves.',
    ]);

    $payload = goodsReceiptPayload([$line], [
        'location_id' => $fx['location'],
        'po_ref' => $fx['poNumber'],
        'supplier_ref' => $fx['supplier'],
    ]);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHas('status', 'Goods receipt posted.');

    $receiptLine = TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceiptLine::query()
        ->where('receipt_id', $payload['receipt_id'])->firstOrFail());

    expect((string) $receiptLine->item_id)->toBe($fx['itemB'])
        ->and((string) $receiptLine->substituted_for_item_id)->toBe($fx['itemA'])
        ->and((string) $receiptLine->po_line_ref)->toBe($fx['lineA']);

    $movement = TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()
        ->whereKey($receiptLine->movement_id)->firstOrFail());

    expect((string) $movement->item_id)->toBe($fx['itemB'])
        ->and((string) $movement->po_line_ref)->toBe($fx['lineA']);

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
    collect(Queue::pushed(DispatchListenerJob::class))
        ->filter(fn (DispatchListenerJob $j): bool => $j->event instanceof GoodsReceiptPosted
            && $j->event->goodsReceiptId === (string) $payload['receipt_id'])
        ->unique(fn (DispatchListenerJob $j): string => $j->event->eventId)
        ->each(fn (DispatchListenerJob $j) => $j->handle());

    $poLine = TenantContextScope::run($fx['t'], $fx['f'], fn () => PoLine::query()->findOrFail($fx['lineA']));

    expect((string) $poLine->received_qty)->toBe('2.0000');
});

it('posts to the exact PO line when two lines carry the same item', function (): void {
    Queue::fake([DispatchListenerJob::class]);

    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access', 'inventory.receipts.create', 'procurement.access', 'procurement.orders.create',
    ]);

    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $item = (string) seedInventoryItem($t, ['code' => 'DUP-1', 'name' => 'Duplicate item', 'uom' => 'BOX'])->id;
    $location = (string) seedStockLocation($t, $f)->id;
    $supplier = (string) seedSupplier($t)->id;

    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $user->id, $supplier, [
        ['lineType' => 'STOCK', 'itemRef' => $item, 'quantity' => '5', 'uom' => 'BOX', 'unitPrice' => '100'],
        ['lineType' => 'STOCK', 'itemRef' => $item, 'quantity' => '7', 'uom' => 'BOX', 'unitPrice' => '100'],
    ]);

    $lines = TenantContextScope::run($t, null, fn () => PoLine::query()
        ->where('po_id', $poId)->orderBy('line_no')->get());

    $payload = goodsReceiptPayload([
        goodsReceiptLinePayload($item, (string) $lines[1]->id, '3'),
    ], [
        'location_id' => $location,
        'po_ref' => $poNumber,
        'supplier_ref' => $supplier,
    ]);

    $this->actingAs($user)->withSession(['active_facility_id' => $f])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHas('status', 'Goods receipt posted.');

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
    collect(Queue::pushed(DispatchListenerJob::class))
        ->filter(fn (DispatchListenerJob $j): bool => $j->event instanceof GoodsReceiptPosted
            && $j->event->goodsReceiptId === (string) $payload['receipt_id'])
        ->unique(fn (DispatchListenerJob $j): string => $j->event->eventId)
        ->each(fn (DispatchListenerJob $j) => $j->handle());

    $fresh = TenantContextScope::run($t, $f, fn () => PoLine::query()
        ->where('po_id', $poId)->orderBy('line_no')->get());

    expect((string) $fresh[0]->received_qty)->toBe('0.0000')
        ->and((string) $fresh[1]->received_qty)->toBe('3.0000');
});

it('rejects an over-receipt and leaves no header, line or movement behind', function (): void {
    $fx = goodsReceiptFixture();

    $payload = goodsReceiptPayload([
        goodsReceiptLinePayload($fx['itemA'], $fx['lineA'], '11'),
    ], ['location_id' => $fx['location'], 'po_ref' => $fx['poNumber']]);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHasErrors('lines.0.quantity_delivered');

    expect(TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceipt::query()->count()))->toBe(0)
        ->and(TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()->count()))->toBe(0);
});

it('rejects a PO line whose item does not match and writes nothing', function (): void {
    $fx = goodsReceiptFixture();

    $payload = goodsReceiptPayload([
        goodsReceiptLinePayload($fx['itemB'], $fx['lineA'], '2'),
    ], ['location_id' => $fx['location'], 'po_ref' => $fx['poNumber']]);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHasErrors('lines.0.item_id');

    expect(TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceipt::query()->count()))->toBe(0);
});

it('records a fully rejected line without posting stock', function (): void {
    $fx = goodsReceiptFixture();

    $payload = goodsReceiptPayload([
        goodsReceiptLinePayload($fx['itemA'], $fx['lineA'], '2', [
            'quantity_rejected' => '2',
            'condition' => 'WRONG_ITEM',
            'rejection_reason' => 'Supplier sent the wrong product',
        ]),
    ], ['location_id' => $fx['location'], 'po_ref' => $fx['poNumber']]);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHas('status', 'Goods receipt posted.');

    $line = TenantContextScope::run($fx['t'], $fx['f'], fn () => GoodsReceiptLine::query()
        ->where('receipt_id', $payload['receipt_id'])->firstOrFail());
    expect((string) $line->quantity_accepted)->toBe('0.0000')
        ->and($line->movement_id)->toBeNull();

    expect(TenantContextScope::run($fx['t'], $fx['f'], fn () => StockMovement::query()
        ->where('goods_receipt_ref', $payload['receipt_id'])->count()))->toBe(0);
});

it('lists, opens and prints a posted goods receipt', function (): void {
    $fx = goodsReceiptFixture();

    $line = goodsReceiptLinePayload($fx['itemA'], '', '2', ['quantity_rejected' => '0']);
    unset($line['po_line_id']);

    $payload = goodsReceiptPayload([$line], [
        'location_id' => $fx['location'],
        'source_type' => 'DONATION',
        'invoice_number' => 'INV-DON-1',
        'invoice_status' => 'PENDING',
    ]);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHas('status', 'Goods receipt posted.');

    $this->get('/workspaces/inventory?section=receipts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Index')
            ->where('goodsReceipts.0.receiptNumber', 'GRN-00000001')
            ->where('goodsReceipts.0.invoiceNumber', 'INV-DON-1')
            ->where('goodsReceipts.0.lineCount', 1)
            ->where('goodsReceipts.0.rejectedLineCount', 0)
            ->where('goodsReceiptPagination.total', 1));

    $this->get('/workspaces/inventory?section=receipts&receipt_id='.$payload['receipt_id'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Index')
            ->where('goodsReceiptDetail.receiptNumber', 'GRN-00000001')
            ->where('goodsReceiptDetail.lines.0.itemCode', 'GLOVE-M')
            ->where('goodsReceiptDetail.lines.0.quantityAccepted', '2.0000'));

    $this->get(route('inventory.goods-receipts.print', $payload['receipt_id']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertSee('%PDF', false);
});

it('posts a 500-line delivery as one GRN within the phase gate', function (): void {
    Queue::fake([DispatchListenerJob::class]);

    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access', 'inventory.receipts.create', 'procurement.access', 'procurement.orders.create',
    ]);

    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $item = (string) seedInventoryItem($t, ['code' => 'BULK-1', 'name' => 'Bulk item', 'uom' => 'BOX'])->id;
    $location = (string) seedStockLocation($t, $f)->id;
    $supplier = (string) seedSupplier($t)->id;

    $poLines = [];
    for ($i = 1; $i <= 500; $i++) {
        $poLines[] = [
            'lineType' => 'STOCK',
            'itemRef' => $item,
            'quantity' => '1',
            'uom' => 'BOX',
            'unitPrice' => '100',
        ];
    }

    [$poId, $poNumber] = issuePurchaseOrder($t, $f, (string) $user->id, $supplier, $poLines);

    $lines = TenantContextScope::run($t, null, fn () => PoLine::query()
        ->where('po_id', $poId)->orderBy('line_no')->get());

    $receiptLines = [];
    foreach ($lines as $line) {
        $receiptLines[] = goodsReceiptLinePayload($item, (string) $line->id, '1');
    }

    expect($receiptLines)->toHaveCount(500);

    $payload = goodsReceiptPayload($receiptLines, [
        'location_id' => $location,
        'po_ref' => $poNumber,
        'supplier_ref' => $supplier,
    ]);

    $started = microtime(true);

    $this->actingAs($user)->withSession(['active_facility_id' => $f])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHas('status', 'Goods receipt posted.');

    $elapsed = microtime(true) - $started;

    expect(TenantContextScope::run($t, $f, fn () => GoodsReceipt::query()
        ->whereKey($payload['receipt_id'])->count()))->toBe(1)
        ->and(TenantContextScope::run($t, $f, fn () => GoodsReceiptLine::query()
            ->where('receipt_id', $payload['receipt_id'])->count()))->toBe(500)
        ->and(TenantContextScope::run($t, $f, fn () => StockMovement::query()
            ->where('source_ref', $payload['receipt_id'])->count()))->toBe(500)
        ->and($elapsed)->toBeLessThan(120.0);

    // Project the one document-level event, then prove the 500 PO lines moved
    // exactly once and the order rolled up to FULLY_RECEIVED.
    $this->artisan('outbox:relay', ['--once' => true, '--batch' => 1000])->assertSuccessful();

    $projectionStarted = microtime(true);

    collect(Queue::pushed(DispatchListenerJob::class))
        ->filter(fn (DispatchListenerJob $job): bool => $job->event instanceof GoodsReceiptPosted
            && $job->event->goodsReceiptId === (string) $payload['receipt_id'])
        ->unique(fn (DispatchListenerJob $job): string => $job->event->eventId)
        ->each(fn (DispatchListenerJob $job) => $job->handle());

    $projectionElapsed = microtime(true) - $projectionStarted;

    $order = TenantContextScope::run($t, $f, fn () => PurchaseOrder::query()->findOrFail($poId));
    $received = TenantContextScope::run($t, $f, fn () => PoLine::query()
        ->where('po_id', $poId)->pluck('received_qty')->map(fn ($qty): string => (string) $qty));

    expect($order->status)->toBe(PurchaseOrder::STATUS_FULLY_RECEIVED)
        ->and($received)->toHaveCount(500)
        ->and($received->unique()->values()->all())->toBe(['1.0000'])
        ->and($projectionElapsed)->toBeLessThan(15.0);
});

it('creates the batch for a batch/expiry-tracked line and keeps its expiry', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access', 'inventory.receipts.create',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $item = (string) seedInventoryItem($t, ['code' => 'AMOX', 'uom' => 'BOX'])->id;
    $location = (string) seedStockLocation($t, $f)->id;

    // The factory's expiry state is applied after creation; keep the item simple
    // and patch the tracking flags through the model for this inventory test.
    TenantContextScope::run($t, null, fn () => Item::query()
        ->whereKey($item)->update(['track_batch' => true, 'track_expiry' => true]));

    $payload = goodsReceiptPayload([
        [
            'receipt_line_id' => (string) Str::uuid7(),
            'item_id' => $item,
            'entered_unit' => 'BOX',
            'entered_quantity' => '2',
            'pack_size' => '1',
            'quantity_delivered' => '2',
            'quantity_rejected' => '0',
            'condition' => 'GOOD',
            'batch_number' => 'AMOX-001',
            'expiry_date' => now()->addYear()->toDateString(),
        ],
    ], [
        'location_id' => $location,
        'source_type' => 'DONATION',
        'supplier_ref' => null,
    ]);

    $this->actingAs($user)->withSession(['active_facility_id' => $f])
        ->post('/workspaces/inventory/goods-receipts', $payload)
        ->assertSessionHas('status', 'Goods receipt posted.');

    $line = TenantContextScope::run($t, $f, fn () => GoodsReceiptLine::query()
        ->where('receipt_id', $payload['receipt_id'])->firstOrFail());
    expect($line->batch_id)->not->toBeNull()
        ->and($line->batch_number)->toBe('AMOX-001')
        ->and($line->expiry_date)->not->toBeNull();

    $batch = TenantContextScope::run($t, null, fn () => Batch::query()
        ->where('item_id', $item)->where('batch_number', 'AMOX-001')->first());
    expect($batch)->not->toBeNull()
        ->and((string) $batch->id)->toBe((string) $line->batch_id);

    $movement = TenantContextScope::run($t, $f, fn () => StockMovement::query()
        ->where('goods_receipt_ref', $payload['receipt_id'])->firstOrFail());
    expect((string) $movement->batch_id)->toBe((string) $line->batch_id);
});
