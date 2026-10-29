<?php

declare(strict_types=1);

use App\Domains\Inventory\Domain\Exception\IllegalStockReservationTransition;
use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Domains\Inventory\Domain\Service\StockReservationLifecycle;

$l = new StockReservationLifecycle;

test('assertActive accepts only ACTIVE', function () use ($l) {
    $l->assertActive('X', StockReservation::STATUS_ACTIVE);

    foreach ([
        StockReservation::STATUS_FULFILLED,
        StockReservation::STATUS_RELEASED,
        StockReservation::STATUS_EXPIRED,
        StockReservation::STATUS_CANCELLED,
    ] as $status) {
        expect(fn () => $l->assertActive('X', $status))->toThrow(IllegalStockReservationTransition::class);
    }
});
