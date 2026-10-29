<?php

declare(strict_types=1);

use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Application\Command\RecordConsumption;
use App\Domains\Inventory\Application\Command\ReverseConsumption;
use App\Domains\Inventory\Contracts\Event\StockConsumptionReversed;
use App\Domains\Inventory\Domain\Exception\StockMovementNotReversible;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, a: string, item: string, loc: string, consumption: string}
 */
function consumed(): array
{
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

    $consumptionId = (string) Str::uuid7();
    dispatchInv($t, $f, new RecordConsumption(
        movementId: $consumptionId, actorId: $a,
        itemId: (string) $item->id, locationId: (string) $location->id, quantity: '30',
        sourceType: 'PHARMACY', sourceRef: (string) Str::uuid7(),
    ));

    return ['t' => $t, 'f' => $f, 'a' => $a, 'item' => (string) $item->id, 'loc' => (string) $location->id, 'consumption' => $consumptionId];
}

test('ReverseConsumption appends a compensating IN movement, restores on_hand, and emits StockConsumptionReversed', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'item' => $item, 'loc' => $loc, 'consumption' => $consumption] = consumed();

    $balance = fn () => TenantContextScope::run($t, $f, fn () => StockBalance::query()
        ->where('item_id', $item)->where('location_id', $loc)->first());
    expect((string) $balance()->on_hand_qty)->toBe('70.0000');

    $reversalId = (string) Str::uuid7();
    $sourceRef = (string) Str::uuid7();
    dispatchInv($t, $f, new ReverseConsumption(
        reversalMovementId: $reversalId, actorId: $a, originalMovementId: $consumption, sourceRef: $sourceRef, reason: 'dispensing reversal',
    ));

    expect((string) $balance()->on_hand_qty)->toBe('100.0000');

    $mv = TenantContextScope::run($t, $f, fn () => StockMovement::query()->whereKey($reversalId)->first());
    expect($mv->movement_type)->toBe(StockMovement::TYPE_CONSUMPTION_REVERSAL)
        ->and($mv->direction)->toBe(StockMovement::DIRECTION_IN)
        ->and((string) $mv->quantity)->toBe('30.0000')
        ->and((string) $mv->reverses_movement_id)->toBe($consumption)
        ->and((string) $mv->source_ref)->toBe($sourceRef);

    $event = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', StockConsumptionReversed::class)->first());
    expect(json_decode((string) $event->payload, true)['originalMovementId'])->toBe($consumption);
});

test('a movement can be reversed only once; a non-CONSUMPTION movement is not reversible', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'consumption' => $consumption] = consumed();

    dispatchInv($t, $f, new ReverseConsumption(
        reversalMovementId: (string) Str::uuid7(), actorId: $a, originalMovementId: $consumption,
    ));

    expect(fn () => dispatchInv($t, $f, new ReverseConsumption(
        reversalMovementId: (string) Str::uuid7(), actorId: $a, originalMovementId: $consumption,
    )))->toThrow(StockMovementNotReversible::class);

    // the RECEIPT movement is not reversible
    $receipt = TenantContextScope::run($t, null, fn () => (string) StockMovement::query()
        ->where('movement_type', StockMovement::TYPE_RECEIPT)->value('id'));
    expect(fn () => dispatchInv($t, $f, new ReverseConsumption(
        reversalMovementId: (string) Str::uuid7(), actorId: $a, originalMovementId: $receipt,
    )))->toThrow(StockMovementNotReversible::class);
});
