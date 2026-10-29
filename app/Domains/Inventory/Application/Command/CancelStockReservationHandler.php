<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Service\StockReservationLifecycle;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Domain\StockReservationRepository;
use App\Shared\Tenancy\TenantContext;

/**
 * Orchestrates `CancelStockReservation` (ADR-180 D-E). As
 * `ReleaseStockReservationHandler`, but ACTIVE → CANCELLED with a mandatory
 * `reason` (ROW-ONLY) and `StockReservationReleased(CANCELLED)`.
 *
 * `StockReservationRepository` is the first repository parameter →
 * `StockReservationPolicy::manage`.
 */
final class CancelStockReservationHandler
{
    public function __construct(
        private readonly StockReservationRepository $reservations,
        private readonly StockBalanceRepository $balances,
        private readonly StockReservationLifecycle $lifecycle,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CancelStockReservation $command): void
    {
        $reservation = $this->reservations->getByIdForUpdate($command->reservationId);

        $this->lifecycle->assertActive('CancelStockReservation', (string) $reservation->status);

        $balance = $this->balances->forUpdateOrCreate(
            $this->context->tenantId(),
            (string) $reservation->facility_id,
            (string) $reservation->item_id,
            (string) $reservation->location_id,
            $reservation->batch_id !== null ? (string) $reservation->batch_id : null,
        );

        $balance->release((string) $reservation->reserved_qty);
        $reservation->cancel($command->actorId, $command->reason);

        $this->balances->save($balance);
        $this->reservations->save($reservation);
    }
}
