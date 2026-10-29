<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Contracts\Dto\StockReservationSummary;

/**
 * Read-side port for the Stock Reservation aggregate (IC §6.2 / §6.3;
 * ADR-180 D-G). Returns DTOs only.
 */
interface StockReservationReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the tenant scope (RLS). */
    public function summary(string $reservationId): StockReservationSummary;

    /**
     * Reservations at a facility, optionally filtered by item / location /
     * status / consumer ref.
     *
     * @return list<StockReservationSummary>
     */
    public function search(
        string $facilityId,
        ?string $itemId = null,
        ?string $locationId = null,
        ?string $status = null,
        ?string $consumerRef = null,
    ): array;
}
