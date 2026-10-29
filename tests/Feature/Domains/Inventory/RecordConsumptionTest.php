<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Application\Command\RecordConsumption;
use App\Domains\Inventory\Contracts\Event\StockConsumed;
use App\Domains\Inventory\Domain\Exception\InsufficientStock;
use App\Domains\Inventory\Domain\Exception\StockBatchExpired;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('RecordConsumption decrements the balance, keeps the ledger reconciled, and emits a PHI-free StockConsumed carrying the opaque source ref', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);
    $procedureRef = (string) Str::uuid7();

    dispatchInv($t, $f, new ReceiveStock(
        movementId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '100',
    ));

    dispatchInv($t, $f, new RecordConsumption(
        movementId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '30',
        sourceType: 'clinical_procedure', sourceRef: $procedureRef,
    ));

    $balance = TenantContextScope::run($t, $f, fn () => StockBalance::query()
        ->where('item_id', $item->id)->where('location_id', $location->id)->first());
    expect((string) $balance->on_hand_qty)->toBe('70.0000');

    $movement = TenantContextScope::run($t, $f, fn () => StockMovement::query()
        ->where('movement_type', StockMovement::TYPE_CONSUMPTION)->first());
    expect($movement->direction)->toBe(StockMovement::DIRECTION_OUT)
        ->and($movement->source_type)->toBe('CLINICAL_PROCEDURE')
        ->and($movement->source_ref)->toBe($procedureRef);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', StockConsumed::class)->first());
    expect($outbox->payload)->toContain($procedureRef);

    expect(inventoryReconciles($t, $f, (string) $item->id, (string) $location->id))->toBeTrue();
});

test('RecordConsumption cannot drive stock negative (Inv 5 — no emergency override in Inv.1)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);

    dispatchInv($t, $f, new ReceiveStock(
        movementId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '10',
    ));

    expect(fn () => dispatchInv($t, $f, new RecordConsumption(
        movementId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '11',
    )))->toThrow(InsufficientStock::class);

    $balance = TenantContextScope::run($t, $f, fn () => StockBalance::query()->where('item_id', $item->id)->first());
    expect((string) $balance->on_hand_qty)->toBe('10.0000');
    expect(TenantContextScope::run($t, $f, fn () => StockMovement::query()
        ->where('movement_type', StockMovement::TYPE_CONSUMPTION)->count()))->toBe(0);
});

test('RecordConsumption is idempotent under a retried idempotency key (§81 / §312)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);
    dispatchInv($t, $f, new ReceiveStock(
        movementId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '100',
    ));

    $consume = fn () => new RecordConsumption(
        movementId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '12',
        idempotencyKey: 'dispensing-completed-9',
    );

    dispatchInv($t, $f, $consume());
    dispatchInv($t, $f, $consume());

    expect(TenantContextScope::run($t, $f, fn () => StockMovement::query()
        ->where('movement_type', StockMovement::TYPE_CONSUMPTION)->count()))->toBe(1);
    $balance = TenantContextScope::run($t, $f, fn () => StockBalance::query()->where('item_id', $item->id)->first());
    expect((string) $balance->on_hand_qty)->toBe('88.0000');
});

test('RecordConsumption refuses an expired batch at consume time (Phase 4 expiry gate)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t, ['track_batch' => true, 'track_expiry' => true]);
    $location = seedStockLocation($t, $f);

    TenantContextScope::run($t, null, function () use ($t, $f, $item, $location): void {
        $expired = Batch::factory()->forTenant($t)->forItem((string) $item->id)
            ->create(['batch_number' => 'EXP-CONSUME', 'expiry_date' => CarbonImmutable::now()->subDay()->toDateString()]);

        StockBalance::factory()->forTenant($t)->onHand('50')->create([
            'facility_id' => $f, 'item_id' => (string) $item->id, 'location_id' => (string) $location->id, 'batch_id' => (string) $expired->id,
        ]);
    });

    expect(fn () => dispatchInv($t, $f, new RecordConsumption(
        movementId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '5', batchNumber: 'EXP-CONSUME',
    )))->toThrow(StockBatchExpired::class);

    // Nothing moved and no movement was written.
    $balance = TenantContextScope::run($t, $f, fn () => StockBalance::query()->where('item_id', $item->id)->first());
    expect((string) $balance->on_hand_qty)->toBe('50.0000');
    expect(TenantContextScope::run($t, $f, fn () => StockMovement::query()
        ->where('movement_type', StockMovement::TYPE_CONSUMPTION)->count()))->toBe(0);
});

test('RecordConsumption requires inventory.consumption.record', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture(permissions: ['inventory.access', 'inventory.receipts.create']);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);
    dispatchInv($t, $f, new ReceiveStock(
        movementId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '10',
    ));

    expect(fn () => dispatchInv($t, $f, new RecordConsumption(
        movementId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '1',
    )))->toThrow(AuthorizationException::class);
});
