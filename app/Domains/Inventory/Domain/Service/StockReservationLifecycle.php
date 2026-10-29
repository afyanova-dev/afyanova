<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Service;

use App\Domains\Inventory\Domain\Exception\IllegalStockReservationTransition;
use App\Domains\Inventory\Domain\Model\StockReservation;

/**
 * Stock-reservation lifecycle rules (18-inventory-and-warehousing-domain.md
 * §113; ADR-180 D-C). Pure domain logic, no I/O.
 *
 *   ACTIVE -> FULFILLED   (RecordConsumption(reservationId))
 *   ACTIVE -> RELEASED    (ReleaseStockReservation)
 *   ACTIVE -> CANCELLED   (CancelStockReservation)
 *   ACTIVE -> EXPIRED     (ExpireStockReservation — the sweep; no key)
 *
 * FULFILLED / RELEASED / CANCELLED / EXPIRED are terminal. The sweep handler
 * does NOT use this — it no-ops defensively (the `ExpireReferralHandler` note).
 */
final class StockReservationLifecycle
{
    public function assertActive(string $action, string $status): void
    {
        if ($status !== StockReservation::STATUS_ACTIVE) {
            throw IllegalStockReservationTransition::fromStatus($action, $status);
        }
    }
}
