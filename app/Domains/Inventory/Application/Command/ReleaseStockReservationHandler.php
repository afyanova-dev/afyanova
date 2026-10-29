<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Service\StockReservationLifecycle;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Domain\StockReservationRepository;
use App\Shared\Tenancy\TenantContext;

/**
 * Orchestrates `ReleaseStockReservation` (ADR-180 D-E). Load the reservation
 * `FOR UPDATE` → it must be ACTIVE → load its balance `FOR UPDATE` →
 * `StockBalance::release(reserved_qty)` → `StockReservation::release()`
 * (records `StockReservationReleased(RELEASED)`) → save both (one txn).
 *
 * `StockReservationRepository` is the first repository parameter →
 * `StockReservationPolicy::manage`.
 */
final class ReleaseStockReservationHandler
{
    public function __construct(
        private readonly StockReservationRepository $reservations,
        private readonly StockBalanceRepository $balances,
        private readonly StockReservationLifecycle $lifecycle,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(ReleaseStockReservation $command): void
    {
        $reservation = $this->reservations->getByIdForUpdate($command->reservationId);

        $this->lifecycle->assertActive('ReleaseStockReservation', (string) $reservation->status);

        $balance = $this->balances->forUpdateOrCreate(
            $this->context->tenantId(),
            (string) $reservation->facility_id,
            (string) $reservation->item_id,
            (string) $reservation->location_id,
            $reservation->batch_id !== null ? (string) $reservation->batch_id : null,
        );

        $balance->release((string) $reservation->reserved_qty);
        $reservation->release($command->actorId);

        $this->balances->save($balance);
        $this->reservations->save($reservation);
    }
}
