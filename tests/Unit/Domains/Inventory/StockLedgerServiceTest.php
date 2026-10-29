<?php

declare(strict_types=1);

use App\Domains\Inventory\Contracts\Event\StockAdjusted;
use App\Domains\Inventory\Contracts\Event\StockConsumed;
use App\Domains\Inventory\Contracts\Event\StockReceived;
use App\Domains\Inventory\Domain\Exception\InsufficientStock;
use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\Service\StockLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function invMovement(string $type, string $direction, string $qty): StockMovement
{
    return new StockMovement([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) Str::uuid7(),
        'facility_id' => (string) Str::uuid7(),
        'movement_number' => 'MOV-'.sprintf('%08d', random_int(1, 99999999)),
        'movement_type' => $type,
        'direction' => $direction,
        'quantity' => $qty,
        'uom' => 'TABLET',
        'item_id' => (string) Str::uuid7(),
        'location_id' => (string) Str::uuid7(),
        'batch_id' => null,
        'source_type' => 'MANUAL',
        'source_ref' => null,
        'po_ref' => null,
        'reason_text' => null,
        'actor_user_id' => (string) Str::uuid7(),
        'occurred_at' => CarbonImmutable::now(),
        'recorded_at' => CarbonImmutable::now(),
        'version' => 0,
    ]);
}

function invBalance(string $onHand = '0'): StockBalance
{
    return StockBalance::start(
        (string) Str::uuid7(),
        (string) Str::uuid7(),
        (string) Str::uuid7(),
        (string) Str::uuid7(),
        null,
    )->forceFill(['on_hand_qty' => $onHand]);
}

test('a receipt increments on-hand and records a PHI-free StockReceived', function () {
    $service = new StockLedgerService;
    $balance = invBalance('10');
    $movement = invMovement(StockMovement::TYPE_RECEIPT, StockMovement::DIRECTION_IN, '25');

    $service->post($balance, $movement);

    expect(Quantity::of((string) $balance->on_hand_qty))->toBe('35.0000');
    $events = $balance->releaseEvents();
    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(StockReceived::class)
        ->and($events[0]->quantity)->toBe('25.0000')
        ->and($events[0]->uom)->toBe('TABLET');
});

test('a consumption decrements on-hand and records StockConsumed', function () {
    $service = new StockLedgerService;
    $balance = invBalance('40');
    $service->post($balance, invMovement(StockMovement::TYPE_CONSUMPTION, StockMovement::DIRECTION_OUT, '15'));

    expect(Quantity::of((string) $balance->on_hand_qty))->toBe('25.0000');
    expect($balance->releaseEvents()[0])->toBeInstanceOf(StockConsumed::class);
});

test('a consumption that would drive on-hand negative throws InsufficientStock (Inv 5; no override)', function () {
    $service = new StockLedgerService;
    $balance = invBalance('5');

    expect(fn () => $service->post($balance, invMovement(StockMovement::TYPE_CONSUMPTION, StockMovement::DIRECTION_OUT, '6')))
        ->toThrow(InsufficientStock::class);

    expect(Quantity::of((string) $balance->on_hand_qty))->toBe('5.0000')
        ->and($balance->releaseEvents())->toBe([]);
});

test('an approved DECREASE adjustment posts a compensating movement and records StockAdjusted with the coded reason only', function () {
    $service = new StockLedgerService;
    $balance = invBalance('20');

    $adjustment = StockAdjustment::raise(
        id: (string) Str::uuid7(),
        tenantId: (string) $balance->tenant_id,
        facilityId: (string) $balance->facility_id,
        itemId: (string) $balance->item_id,
        locationId: (string) $balance->location_id,
        batchId: null,
        direction: StockAdjustment::DIRECTION_DECREASE,
        quantity: '3',
        reasonCode: 'DAMAGED',
        reasonText: 'water damage in bay 4',
        requestedByUserId: (string) Str::uuid7(),
        requestedAt: CarbonImmutable::now(),
    );
    $adjustment->markApproved((string) Str::uuid7(), (string) Str::uuid7(), CarbonImmutable::now());

    $movement = invMovement(StockMovement::TYPE_ADJUSTMENT, StockMovement::DIRECTION_OUT, '3');
    $service->postAdjustment($balance, $movement, $adjustment);

    expect(Quantity::of((string) $balance->on_hand_qty))->toBe('17.0000');
    $events = $balance->releaseEvents();
    expect($events[0])->toBeInstanceOf(StockAdjusted::class)
        ->and($events[0]->reasonCode)->toBe('DAMAGED')
        ->and(json_encode($events[0]))->not->toContain('water damage');
});
