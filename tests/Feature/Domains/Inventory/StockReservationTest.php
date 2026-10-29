<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\CancelStockReservation;
use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Application\Command\RecordConsumption;
use App\Domains\Inventory\Application\Command\ReleaseStockReservation;
use App\Domains\Inventory\Application\Command\ReserveStock;
use App\Domains\Inventory\Application\Query\GetStockBalance;
use App\Domains\Inventory\Application\Query\GetStockReservation;
use App\Domains\Inventory\Application\Query\SearchStockReservations;
use App\Domains\Inventory\Contracts\Event\StockReservationReleased;
use App\Domains\Inventory\Contracts\Event\StockReserved;
use App\Domains\Inventory\Domain\Exception\IllegalStockReservationTransition;
use App\Domains\Inventory\Domain\Exception\InsufficientStock;
use App\Domains\Inventory\Domain\Exception\StockBatchExpired;
use App\Domains\Inventory\Domain\Exception\StockReservationMismatch;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, a: string, item: string, location: string}
 */
function reservationSetup(string $onHand = '100'): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access', 'inventory.receipts.create', 'inventory.consumption.record', 'inventory.reservations.manage',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t);
    $location = seedStockLocation($t, $f);
    dispatchInv($t, $f, new ReceiveStock(
        movementId: (string) Str::uuid7(), actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: $onHand,
    ));

    return ['t' => $t, 'f' => $f, 'a' => $a, 'item' => (string) $item->id, 'location' => (string) $location->id];
}

function balanceOf(string $t, string $f, string $itemId): StockBalance
{
    return TenantContextScope::run($t, $f, fn () => StockBalance::query()->where('item_id', $itemId)->first());
}

test('ReserveStock increments reserved_qty without moving on_hand; available drops; StockReserved is PHI-free', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'location' => $location] = reservationSetup();
    $consumerRef = 'SURG-00123';

    $reservationId = (string) Str::uuid7();
    dispatchInv($t, $f, new ReserveStock(
        reservationId: $reservationId, actorId: $a, itemId: $item, locationId: $location,
        quantity: '40', consumerType: 'theatre_case', consumerRef: $consumerRef,
    ));

    $balance = balanceOf($t, $f, $item);
    expect((string) $balance->on_hand_qty)->toBe('100.0000')
        ->and((string) $balance->reserved_qty)->toBe('40.0000')
        ->and($balance->available())->toBe('60.0000')
        ->and((int) $balance->version)->toBeGreaterThan(0);

    $summary = queryInv($t, $f, new GetStockReservation($reservationId));
    expect($summary->status)->toBe(StockReservation::STATUS_ACTIVE)
        ->and($summary->reservedQty)->toBe('40.0000')
        ->and($summary->consumerType)->toBe('THEATRE_CASE')
        ->and($summary->consumerRef)->toBe($consumerRef);

    $bal = queryInv($t, $f, new GetStockBalance(itemId: $item, locationId: $location));
    expect($bal[0]->reservedQty)->toBe('40.0000')->and($bal[0]->availableQty)->toBe('60.0000');

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', StockReserved::class)->first());
    expect($outbox->payload)->toContain($consumerRef)->toContain('40')
        ->and($outbox->payload)->not->toContain('THEATRE_CASE SURG'); // no free text
});

test('Inv 17 — a reservation cannot exceed the currently available quantity', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'location' => $location] = reservationSetup('50');

    dispatchInv($t, $f, new ReserveStock(
        reservationId: (string) Str::uuid7(), actorId: $a, itemId: $item, locationId: $location, quantity: '30',
    ));

    expect(fn () => dispatchInv($t, $f, new ReserveStock(
        reservationId: (string) Str::uuid7(), actorId: $a, itemId: $item, locationId: $location, quantity: '25',
    )))->toThrow(InsufficientStock::class);

    // Exactly filling the remaining 20 is allowed.
    dispatchInv($t, $f, new ReserveStock(
        reservationId: (string) Str::uuid7(), actorId: $a, itemId: $item, locationId: $location, quantity: '20',
    ));
    expect(balanceOf($t, $f, $item)->available())->toBe('0.0000');
});

test('Release and Cancel return reserved stock; a released reservation cannot be released again', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'location' => $location] = reservationSetup();

    $r1 = (string) Str::uuid7();
    $r2 = (string) Str::uuid7();
    dispatchInv($t, $f, new ReserveStock(reservationId: $r1, actorId: $a, itemId: $item, locationId: $location, quantity: '30'));
    dispatchInv($t, $f, new ReserveStock(reservationId: $r2, actorId: $a, itemId: $item, locationId: $location, quantity: '25'));
    expect((string) balanceOf($t, $f, $item)->reserved_qty)->toBe('55.0000');

    dispatchInv($t, $f, new ReleaseStockReservation($r1, $a));
    dispatchInv($t, $f, new CancelStockReservation($r2, $a, 'case cancelled'));

    expect((string) balanceOf($t, $f, $item)->reserved_qty)->toBe('0.0000')
        ->and(queryInv($t, $f, new GetStockReservation($r1))->status)->toBe(StockReservation::STATUS_RELEASED)
        ->and(queryInv($t, $f, new GetStockReservation($r2))->status)->toBe(StockReservation::STATUS_CANCELLED)
        ->and(queryInv($t, $f, new GetStockReservation($r2))->reason)->toBe('case cancelled');

    expect(fn () => dispatchInv($t, $f, new ReleaseStockReservation($r1, $a)))
        ->toThrow(IllegalStockReservationTransition::class);

    $released = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', StockReservationReleased::class)->count());
    expect($released)->toBe(2);
});

test('the sweep releases a lapsed reservation and ReserveStock self-heals on touch', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'location' => $location] = reservationSetup('50');

    $lapsing = (string) Str::uuid7();
    dispatchInv($t, $f, new ReserveStock(
        reservationId: $lapsing, actorId: $a, itemId: $item, locationId: $location,
        quantity: '40', holdUntil: now()->addMinutes(5)->toIso8601String(),
    ));
    // Force the hold into the past (no command shortens a live hold).
    TenantContextScope::run($t, null, fn () => StockReservation::query()->whereKey($lapsing)
        ->update(['hold_until' => now()->subMinute()]));

    // Self-heal: a fresh reserve for 40 would fail (only 10 available) unless the
    // lapsed 40 is released first.
    dispatchInv($t, $f, new ReserveStock(
        reservationId: (string) Str::uuid7(), actorId: $a, itemId: $item, locationId: $location, quantity: '40',
    ));

    expect(queryInv($t, $f, new GetStockReservation($lapsing))->status)->toBe(StockReservation::STATUS_EXPIRED)
        ->and((string) balanceOf($t, $f, $item)->reserved_qty)->toBe('40.0000');

    // Sweep is idempotent + also handles a reservation nothing ever touches.
    $orphan = (string) Str::uuid7();
    dispatchInv($t, $f, new ReserveStock(
        reservationId: $orphan, actorId: $a, itemId: $item, locationId: $location,
        quantity: '5', holdUntil: now()->addMinutes(5)->toIso8601String(),
    ));
    TenantContextScope::run($t, null, fn () => StockReservation::query()->whereKey($orphan)
        ->update(['hold_until' => now()->subMinute()]));

    test()->artisan('inventory:expire-reservations', ['--once' => true])->assertSuccessful();
    test()->artisan('inventory:expire-reservations', ['--once' => true])->assertSuccessful();

    expect(queryInv($t, $f, new GetStockReservation($orphan))->status)->toBe(StockReservation::STATUS_EXPIRED)
        ->and((string) balanceOf($t, $f, $item)->reserved_qty)->toBe('40.0000');
});

test('RecordConsumption(reservationId) fulfils the reservation — reserved_qty AND on_hand both drop', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'location' => $location] = reservationSetup();

    $reservationId = (string) Str::uuid7();
    dispatchInv($t, $f, new ReserveStock(
        reservationId: $reservationId, actorId: $a, itemId: $item, locationId: $location, quantity: '30',
    ));

    dispatchInv($t, $f, new RecordConsumption(
        movementId: (string) Str::uuid7(), actorId: $a, itemId: $item, locationId: $location,
        quantity: '30', sourceType: 'theatre', reservationId: $reservationId,
    ));

    $balance = balanceOf($t, $f, $item);
    expect((string) $balance->on_hand_qty)->toBe('70.0000')
        ->and((string) $balance->reserved_qty)->toBe('0.0000')
        ->and($balance->available())->toBe('70.0000');

    $reservation = queryInv($t, $f, new GetStockReservation($reservationId));
    expect($reservation->status)->toBe(StockReservation::STATUS_FULFILLED)
        ->and($reservation->fulfilledByMovementId)->not->toBeNull();

    expect(inventoryReconciles($t, $f, $item, $location))->toBeTrue();
});

test('RecordConsumption(reservationId) is rejected on a mismatched item / insufficient reserved / non-ACTIVE reservation', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'location' => $location] = reservationSetup();
    $otherItem = (string) seedInventoryItem($t)->id;
    dispatchInv($t, $f, new ReceiveStock(
        movementId: (string) Str::uuid7(), actorId: $a, itemId: $otherItem, locationId: $location, quantity: '10',
    ));

    $reservationId = (string) Str::uuid7();
    dispatchInv($t, $f, new ReserveStock(
        reservationId: $reservationId, actorId: $a, itemId: $item, locationId: $location, quantity: '10',
    ));

    expect(fn () => dispatchInv($t, $f, new RecordConsumption(
        movementId: (string) Str::uuid7(), actorId: $a, itemId: $otherItem, locationId: $location,
        quantity: '5', reservationId: $reservationId,
    )))->toThrow(StockReservationMismatch::class);

    expect(fn () => dispatchInv($t, $f, new RecordConsumption(
        movementId: (string) Str::uuid7(), actorId: $a, itemId: $item, locationId: $location,
        quantity: '15', reservationId: $reservationId,
    )))->toThrow(StockReservationMismatch::class);

    dispatchInv($t, $f, new ReleaseStockReservation($reservationId, $a));
    expect(fn () => dispatchInv($t, $f, new RecordConsumption(
        movementId: (string) Str::uuid7(), actorId: $a, itemId: $item, locationId: $location,
        quantity: '5', reservationId: $reservationId,
    )))->toThrow(IllegalStockReservationTransition::class);
});

test('a plain RecordConsumption (no reservationId) still cannot eat into reserved stock (Inv 19)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'location' => $location] = reservationSetup('50');

    dispatchInv($t, $f, new ReserveStock(
        reservationId: (string) Str::uuid7(), actorId: $a, itemId: $item, locationId: $location, quantity: '40',
    ));

    // 10 available; a 20 consumption not against the reservation is refused.
    expect(fn () => dispatchInv($t, $f, new RecordConsumption(
        movementId: (string) Str::uuid7(), actorId: $a, itemId: $item, locationId: $location, quantity: '20', sourceType: 'ward',
    )))->toThrow(InsufficientStock::class);

    // 10 is fine.
    dispatchInv($t, $f, new RecordConsumption(
        movementId: (string) Str::uuid7(), actorId: $a, itemId: $item, locationId: $location, quantity: '10', sourceType: 'ward',
    ));
    expect((string) balanceOf($t, $f, $item)->on_hand_qty)->toBe('40.0000')
        ->and((string) balanceOf($t, $f, $item)->reserved_qty)->toBe('40.0000');
});

test('SearchStockReservations filters; inv_stock_reservations is tenant-isolated (FORCE RLS)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'location' => $location] = reservationSetup();

    dispatchInv($t, $f, new ReserveStock(reservationId: (string) Str::uuid7(), actorId: $a, itemId: $item, locationId: $location, quantity: '10', consumerRef: 'A'));
    dispatchInv($t, $f, new ReserveStock(reservationId: (string) Str::uuid7(), actorId: $a, itemId: $item, locationId: $location, quantity: '10', consumerRef: 'B'));

    expect(queryInv($t, $f, new SearchStockReservations(facilityId: $f, status: 'ACTIVE')))->toHaveCount(2)
        ->and(queryInv($t, $f, new SearchStockReservations(facilityId: $f, consumerRef: 'A')))->toHaveCount(1)
        ->and(queryInv($t, $f, new SearchStockReservations(facilityId: $f, itemId: (string) Str::uuid7())))->toHaveCount(0);

    $policies = DB::table('pg_policies')->where('tablename', 'inv_stock_reservations')->pluck('policyname')->all();
    expect($policies)->toContain('tenant_isolation');
    $forced = DB::selectOne("select relforcerowsecurity as f from pg_class where relname = 'inv_stock_reservations'");
    expect($forced->f)->toBeTrue();
});

test('ReserveStock refuses an expired batch (Phase 4 expiry gate)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = inventoryFixture([
        'inventory.access', 'inventory.receipts.create', 'inventory.reservations.manage',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $item = seedInventoryItem($t, ['track_batch' => true, 'track_expiry' => true]);
    $location = seedStockLocation($t, $f);

    TenantContextScope::run($t, null, function () use ($t, $f, $item, $location): void {
        $expired = Batch::factory()->forTenant($t)->forItem((string) $item->id)
            ->create(['batch_number' => 'EXP-RESERVE', 'expiry_date' => CarbonImmutable::now()->subDay()->toDateString()]);

        StockBalance::factory()->forTenant($t)->onHand('50')->create([
            'facility_id' => $f, 'item_id' => (string) $item->id, 'location_id' => (string) $location->id, 'batch_id' => (string) $expired->id,
        ]);
    });

    expect(fn () => dispatchInv($t, $f, new ReserveStock(
        reservationId: (string) Str::uuid7(), actorId: $a, itemId: (string) $item->id,
        locationId: (string) $location->id, quantity: '5', batchNumber: 'EXP-RESERVE',
    )))->toThrow(StockBatchExpired::class);

    // No reservation and no reserved_qty movement.
    expect(balanceOf($t, $f, (string) $item->id)->available())->toBe('50.0000');
    expect(TenantContextScope::run($t, $f, fn () => StockReservation::query()->count()))->toBe(0);
});
