<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Stock Reservation aggregate (IC §9; ADR-180 D-C).
 * This is the FIRST repository parameter of the reservation commands — the bus
 * resolves `StockReservationPolicy` from it.
 */
interface StockReservationRepository extends Repository
{
    public function getById(string $id): StockReservation;

    public function getByIdForUpdate(string $id): StockReservation;

    /**
     * Every ACTIVE reservation whose `hold_until` has lapsed, for one
     * (item, location, batch) tuple — the `ReserveStock` self-heal (ADR-180 D-E).
     *
     * @return list<StockReservation>
     */
    public function lapsedForTuple(string $itemId, string $locationId, ?string $batchId): array;
}
