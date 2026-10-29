<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Domain\StockReservationRepository;
use App\Shared\Tenancy\TenantContext;

/**
 * Orchestrates `ExpireStockReservation` (ADR-180 D-E — the sweep).
 *
 * Defensive no-op unless the reservation is ACTIVE AND `isLapsed()` — a sweep
 * may race a human `Release` / `Cancel` / a `RecordConsumption` fulfilment, and
 * a double sweep is safe. `StockReservationLifecycle::assertActive` is
 * deliberately NOT used (the `ExpireReferralHandler` note). Releases the held
 * `reserved_qty` + records `StockReservationReleased(EXPIRED)`.
 *
 * `StockReservation` is not `AuthorizesAgainst`, so the bus skips the policy —
 * system command.
 */
final class ExpireStockReservationHandler
{
    public function __construct(
        private readonly StockReservationRepository $reservations,
        private readonly StockBalanceRepository $balances,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(ExpireStockReservation $command): void
    {
        $reservation = $this->reservations->getByIdForUpdate($command->reservationId);

        if ((string) $reservation->status !== StockReservation::STATUS_ACTIVE || ! $reservation->isLapsed()) {
            return; // not a candidate — already resolved, or the hold has not lapsed
        }

        $balance = $this->balances->forUpdateOrCreate(
            $this->context->tenantId(),
            (string) $reservation->facility_id,
            (string) $reservation->item_id,
            (string) $reservation->location_id,
            $reservation->batch_id !== null ? (string) $reservation->batch_id : null,
        );

        $balance->release((string) $reservation->reserved_qty);
        $reservation->expire();

        $this->balances->save($balance);
        $this->reservations->save($reservation);
    }
}
