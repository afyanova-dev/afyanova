<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Contracts\Event\StockReceived;
use App\Domains\Inventory\Domain\Exception\InventoryItemNotStockable;
use App\Domains\Inventory\Domain\Exception\StockBatchAlreadyExpired;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('ReceiveStock appends an immutable MOV movement, materialises the balance, emits a PHI-free StockReceived, audits, and reconciles', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t, ['uom' => 'TABLET']);
    $location = seedStockLocation($t, $f);
    $movementId = (string) Str::uuid7();

    dispatchInv($t, $f, new ReceiveStock(
        movementId: $movementId,
        actorId: $a,
        itemId: (string) $item->id,
        locationId: (string) $location->id,
        quantity: '250',
        poRef: 'PO-9001',
        reason: 'Opening delivery — batch on the ward for Mrs Jane Doe.',
    ));

    $movement = TenantContextScope::run($t, $f, fn () => StockMovement::query()->find($movementId));
    expect($movement)->not->toBeNull()
        ->and($movement->movement_type)->toBe(StockMovement::TYPE_RECEIPT)
        ->and($movement->direction)->toBe(StockMovement::DIRECTION_IN)
        ->and($movement->movement_number)->toStartWith('MOV-')
        ->and($movement->uom)->toBe('TABLET')
        ->and($movement->po_ref)->toBe('PO-9001')
        ->and((int) $movement->version)->toBe(0);

    $balance = TenantContextScope::run($t, $f, fn () => StockBalance::query()
        ->where('item_id', $item->id)->where('location_id', $location->id)->first());
    expect((string) $balance->on_hand_qty)->toBe('250.0000')
        ->and($balance->available())->toBe('250.0000')
        ->and((string) $balance->facility_id)->toBe($f);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', StockReceived::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload)->not->toContain('Jane Doe')
        ->and($outbox->payload)->toContain($movement->movement_number);

    expect(Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('target_id', $movementId)->where('action', 'inventory.stock_received')->exists()))->toBeTrue();

    expect(inventoryReconciles($t, $f, (string) $item->id, (string) $location->id))->toBeTrue();
});

test('two receipts of a non-batch item collapse to one balance row (NULLS NOT DISTINCT — blocker B1)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);

    foreach (['100', '40'] as $qty) {
        dispatchInv($t, $f, new ReceiveStock(
            movementId: (string) Str::uuid7(), actorId: $a,
            itemId: (string) $item->id, locationId: (string) $location->id, quantity: $qty,
        ));
    }

    $rows = TenantContextScope::run($t, $f, fn () => StockBalance::query()
        ->where('item_id', $item->id)->where('location_id', $location->id)->get());
    expect($rows)->toHaveCount(1)
        ->and((string) $rows->first()->on_hand_qty)->toBe('140.0000');

    expect(inventoryReconciles($t, $f, (string) $item->id, (string) $location->id))->toBeTrue();
});

test('a batch-tracked, expiry-tracked item creates the batch on first receipt and reuses it on the second', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t, ['track_batch' => true, 'track_expiry' => true]);
    $location = seedStockLocation($t, $f);

    foreach (['60', '30'] as $qty) {
        dispatchInv($t, $f, new ReceiveStock(
            movementId: (string) Str::uuid7(), actorId: $a,
            itemId: (string) $item->id, locationId: (string) $location->id, quantity: $qty,
            batchNumber: 'LOT-77', expiryDate: '2028-01-31',
        ));
    }

    $batches = TenantContextScope::run($t, $f, fn () => Batch::query()->where('item_id', $item->id)->get());
    expect($batches)->toHaveCount(1)
        ->and($batches->first()->batch_number)->toBe('LOT-77')
        ->and($batches->first()->expiry_date->toDateString())->toBe('2028-01-31');

    $balance = TenantContextScope::run($t, $f, fn () => StockBalance::query()
        ->where('item_id', $item->id)->where('batch_id', $batches->first()->id)->first());
    expect((string) $balance->on_hand_qty)->toBe('90.0000');
});

test('a batch-tracked item receipt without a batch number is rejected; an expiry-tracked one without an expiry date is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $batchItem = seedInventoryItem($t, ['track_batch' => true]);
    $expiryItem = seedInventoryItem($t, ['track_batch' => true, 'track_expiry' => true]);
    $location = seedStockLocation($t, $f);

    expect(fn () => dispatchInv($t, $f, new ReceiveStock(
        movementId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $batchItem->id, locationId: (string) $location->id, quantity: '10',
    )))->toThrow(InventoryItemNotStockable::class);

    expect(fn () => dispatchInv($t, $f, new ReceiveStock(
        movementId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $expiryItem->id, locationId: (string) $location->id, quantity: '10', batchNumber: 'B1',
    )))->toThrow(InventoryItemNotStockable::class);
});

test('ReceiveStock requires inventory.receipts.create', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture(permissions: ['inventory.access']);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);

    expect(fn () => dispatchInv($t, $f, new ReceiveStock(
        movementId: (string) Str::uuid7(), actorId: (string) $user->id,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '10',
    )))->toThrow(AuthorizationException::class);
});

test('inv_stock_movements is append-only at the database — UPDATE / DELETE affect 0 rows (Inv 3 / Inv 15)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    ['movementId' => $movementId] = receiveStock($t, $f, (string) $user->id, '10');

    $policies = DB::table('pg_policies')->where('tablename', 'inv_stock_movements')->pluck('policyname')->all();
    expect($policies)->toContain('inv_stock_movements_no_update')
        ->and($policies)->toContain('inv_stock_movements_no_delete')
        ->and($policies)->toContain('tenant_isolation');

    $raw = DB::connection('pgsql_raw');
    $raw->transaction(function () use ($raw, $t, $movementId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$t]);
        expect($raw->table('inv_stock_movements')->where('id', $movementId)->update(['quantity' => 999]))->toBe(0);
        expect($raw->table('inv_stock_movements')->where('id', $movementId)->delete())->toBe(0);
    });

    expect(Tenancy::run($t, fn () => (string) DB::table('inv_stock_movements')->where('id', $movementId)->value('quantity')))->toBe('10.0000');
});

test('ReceiveStock is idempotent under a retried idempotency key', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);
    $movementId = (string) Str::uuid7();

    $cmd = fn () => new ReceiveStock(
        movementId: $movementId, actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '75',
        idempotencyKey: 'goods-receipt-42',
    );

    dispatchInv($t, $f, $cmd());
    dispatchInv($t, $f, $cmd());

    expect(TenantContextScope::run($t, $f, fn () => StockMovement::query()->where('item_id', $item->id)->count()))->toBe(1);
    $balance = TenantContextScope::run($t, $f, fn () => StockBalance::query()->where('item_id', $item->id)->first());
    expect((string) $balance->on_hand_qty)->toBe('75.0000');
});

test('a receipt whose batch is already expired at the receipt date is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $item = seedInventoryItem($t, ['track_batch' => true, 'track_expiry' => true]);
    $location = seedStockLocation($t, $f);

    expect(fn () => dispatchInv($t, $f, new ReceiveStock(
        movementId: (string) Str::uuid7(), actorId: (string) $user->id,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '10',
        batchNumber: 'LOT-EXPIRED', expiryDate: now()->subDay()->toDateString(),
    )))->toThrow(StockBatchAlreadyExpired::class);
});

test('a backdated receipt is accepted when the batch was valid on its receipt date even if expired now', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $item = seedInventoryItem($t, ['track_batch' => true, 'track_expiry' => true]);
    $location = seedStockLocation($t, $f);
    $movementId = (string) Str::uuid7();

    dispatchInv($t, $f, new ReceiveStock(
        movementId: $movementId, actorId: (string) $user->id,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '10',
        batchNumber: 'LOT-HISTORIC',
        expiryDate: now()->subDays(5)->toDateString(),
        occurredAt: now()->subDays(10)->toDateTimeString(),
        reason: 'Recording a historic delivery during migration.',
    ));

    expect(TenantContextScope::run($t, $f, fn () => StockMovement::query()->find($movementId)))->not->toBeNull();
});
