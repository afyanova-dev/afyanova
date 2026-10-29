<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain;

use App\Domains\Pharmacy\Contracts\Dto\DispensingSummary;

/**
 * Read-side port for the Dispensing aggregate (IC §6.2 / §6.3; ADR-184 D-I).
 * Returns DTOs / scalars only, never an Eloquent model.
 */
interface DispensingReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the current tenant scope (RLS). */
    public function summary(string $dispensingId): DispensingSummary;

    /**
     * Every Dispensing for one Pharmacy Order, oldest first (the fulfilment
     * history — §127 / §255; also the `Dispense` handler's first-event check).
     *
     * @return list<DispensingSummary>
     */
    public function forOrder(string $pharmacyOrderId): array;

    /**
     * The NET cumulative dispensed quantity for one Pharmacy Order as a
     * fixed-scale decimal string — `Σ(rx_dispensings.quantity)
     * − Σ(rx_dispensing_reversals.quantity)` (ADR-185 D-E), `'0.0000'` when
     * nothing net has been dispensed. Read under the caller's `PharmacyOrder`
     * row-lock (ADR-184 D-E / the §112 concurrency guard).
     */
    public function cumulativeForOrder(string $pharmacyOrderId): string;
}
