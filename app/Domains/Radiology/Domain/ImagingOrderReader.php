<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain;

use App\Domains\Radiology\Contracts\Dto\ImagingOrderSummary;

/**
 * Read-side port for ImagingOrder queries (ADR-165 D-H). Returns DTOs only —
 * never an Eloquent model (IC §9).
 */
interface ImagingOrderReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the order is absent from the
     * current tenant scope (RLS).
     */
    public function summary(string $orderId): ImagingOrderSummary;

    /**
     * Active (non-terminal) orders at a facility — the radiology work queue
     * (§76, §133).
     *
     * @return list<ImagingOrderSummary>
     */
    public function activeForFacility(string $facilityId): array;

    /**
     * All imaging orders for a patient, tenant-wide (§133) — the
     * `GetPatientLabOrders` tenant-wide-scope precedent.
     *
     * @return list<ImagingOrderSummary>
     */
    public function forPatient(string $patientId): array;

    /** Count of non-CANCELLED items on an order (the SubmitImagingOrder guard). */
    public function activeItemCount(string $orderId): int;
}
